<?php

/**
 * Validates multiple attributes using any Yii Core Validator
 * depending on some another attribute's condition (validation) is true;
 *
 * @author    Sidney Lins <solucoes@wmaior.com>
 * @copyright 2011 Sidney Lins
 * @license   New BSD Licence
 * @version   Release: 0.2.0
 */
class YiiConditionalValidator extends CValidator
{
    public $validation = array('required');
    public $dependentValidations = array();
    public $message = "{dependentAttribute} is invalid.";
    
    /**
     * Whether to skip validation if the dependent validators failed. Useful for
     * running validation based on a user-entered value.
     * @var bool
     */
    public $skipOnDependencyError = false;
    
    /**
     * Validate the attribute conditionally
     *
     * @param CActiveRecord $object    the model object
     * @param string        $attribute the attribute name
     *          Can specify related model via "relatedModel.attribute"
     *
     * @return void
     */
    protected function validateAttribute($object, $attribute)
    {
        // Get any errors on dependent attributes
        $errors = $this->validateDependentAttributes($object);
        
        if ($errors) {
            if ($this->skipOnDependencyError) {
                return;
            }
            
            // Apply dependecy errors
            foreach ($errors as $error) {
                $this->applyDependentValidationError($error, $object, $attribute);
            }
        }
        
        // No dependency errors. Run validator
        $this->runValidator($object, $attribute, $this->validation);
    }
    
    /**
     * Validate dependent attributes
     *
     * @param CActiveRecord $object the model object
     *
     * @return array of errors (object, attribute, message)
     */
    protected function validateDependentAttributes($object)
    {
        $errors = array();
        
        foreach ($this->dependentValidations as $attribute => $validationData) {
            if (!is_array($validationData) || !count($validationData)) {
                throw new CException(
                    'YiiConditionalValidator: dependentAttributesAndValidators '
                    . 'must be an array and must have at least one value.'
                );
            }
            
            $attributes = array_map('trim', explode(',', $attribute));
            
            foreach ($attributes as $attribute) {
                foreach ($validationData as $validation) {
                    $validateTarget = $this
                        ->getValidationTarget($object, $attribute);
                    
                    if (is_null($validateTarget)) {
                        // If it's possible that the relation doesn't exist, this
                        // shoudl be a separate validation rule that occurs before
                        // this one.
                        continue;
                    }
                    
                    // relObject
                    $errorMessage = $this->runValidatorKeepExisting(
                        $validateTarget['object'],
                        $validateTarget['attribute'],
                        $validation
                    );
                    
                    if (!empty($errorMessage)) {
                        $errorMessage = isset($validationData['message'])
                            ? $validationData['message'] : $errorMessage;
                        
                        $errors[] = array(
                            'object' => $validateTarget['object'],
                            'attribute' => $validateTarget['attribute'],
                            'message'  => $errorMessage,
                        );
                    }
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Get the validation target. May be a related object attribute.
     *
     * @param CActiveRecord $object    the model object
     * @param string        $attribute the attribute
     *
     * @return array(object => $object, attribute => $attribute) or null
     */
    protected function getValidationTarget($object, $attribute)
    {
        $validateAttribute = $attribute;
        $validateObject = $object;
        
        // If this is a related object, parse and retrieve
        if (strpos($validateAttribute, '.') !== false) {
            $parts = explode('.', $attribute);
            
            $validateAttribute = $parts[1];
            $validateObject = $object->getRelated($parts[0]);
        }
        
        if (is_null($validateObject)) {
            return null;
        }
        
        return array(
            'object' => $validateObject,
            'attribute' => $validateAttribute,
        );
    }
    
    /**
     * Apply errors for dependent attribute validation. Errors are applied to the
     * original attribute on the original object
     *
     * @param array         $error             (object, attribute, message)
     * @param CActiveRecord $originalObject    the original model object
     * @param string        $originalAttribute the original attribute
     *
     * @return void
     */
    protected function applyDependentValidationError(
        $error, $originalObject, $originalAttribute
    ) {
        $dependentObject = $error['object'];
        $dependentAttribute = $error['attribute'];
        $message = $error['message'];
        
        $originalObject->addError(
            $originalAttribute,
            Yii::t(
                'yii',
                $message,
                array(
                    '{attribute}'
                        => $originalObject->getAttributeLabel($originalAttribute),
                    '{value}'
                        => $originalObject->{$originalAttribute},
                    '{dependentAttribute}'
                        => $dependentObject->getAttributeLabel($dependentAttribute),
                    '{dependentValue}'
                        => $dependentObject->{$dependentAttribute},
                )
            )
        );
    }
    
    /**
     * Run a validator but keep existing errors without modification
     *
     * @param CActiveRecord $object     the model object
     * @param string        $attribute  the attribute
     * @param array         $validation the validator definition
     *
     * @return string|null the error message if validation failed, or null
     */
    protected function runValidatorKeepExisting(
        $object, $attribute, $validation
    ) {
        // Back up and clear errors
        $errorsBackup = $object->getErrors();
        $object->clearErrors();
        
        $this->runValidator($object, $attribute, $validation);
        
        // Record error
        $errorMessage = $object->getError($attribute);
        
        // Restore existing errors
        $object->clearErrors();
        $object->addErrors($errorsBackup);
        
        return $errorMessage;
    }
    
    /**
     * Run a validator
     *
     * @param CActiveRecord $object     the model object
     * @param string        $attribute  the attribute
     * @param array         $validation the validator definition
     *
     * @return void
     */
    protected function runValidator(
        $object, $attribute, $validation
    ) {
        $validatorName = $validation[0];
        $validatorParams = array_slice($validation, 1, count($validation) - 1);
        
        $validator = CValidator::createValidator(
            $validatorName, $object, $attribute, $validatorParams
        );
        
        $validator->validate($object, $attribute);
    }
}
