<?php

namespace Icinga\Module\Director\Web\Form\Element;

use Icinga\Module\Director\CustomVariable\CustomVariable;
use Icinga\Module\Director\Web\Form\IplElement\ExtensibleSetElement;
use InvalidArgumentException;

/**
 * Input control for extensible sets
 */
class ExtensibleSet extends FormElement
{
    /**
     * Default form view helper to use for rendering
     * @var string
     */
    public $helper = 'formIplExtensibleSet';

   // private $multiOptions;

    public function getValue()
    {
        $value = parent::getValue();
        if (is_string($value) || is_numeric($value)) {
            $value = [$value];
        } elseif ($value === null) {
            return $value;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'ExtensibleSet expects to work with Arrays, got %s',
                var_export($value, true)
            ));
        }
        $value = array_filter($value, 'strlen');

        if (empty($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Reject entries overriding and modifying an inherited value at once
     *
     * Icinga 2 either replaces an array or extends it, so mixing '=' with '+'
     * or '-' makes no sense. Prefilled entries are implicit and do not count
     *
     * @param array      $value
     * @param array|null $context All submitted form values
     * @return bool
     */
    protected function entryOperatorsAreValid($value, $context)
    {
        if (! $this->getAttrib('withOperators') || ! is_array($context)) {
            return true;
        }

        $key = $this->getName() . ExtensibleSetElement::OPERATOR_SUFFIX;
        if (! isset($context[$key]) || ! is_array($context[$key])) {
            return true;
        }

        $operators = array_values($context[$key]);
        $prefilled = (array) $this->getAttrib('prefilled');
        $gotOverride = false;
        $gotDelta = false;

        foreach (array_values($value) as $idx => $entry) {
            $operator = isset($operators[$idx]) ? $operators[$idx] : CustomVariable::OPERATOR_SET;
            if ($operator !== CustomVariable::OPERATOR_ADD && $operator !== CustomVariable::OPERATOR_REMOVE) {
                if (! in_array($entry, $prefilled)) {
                    $gotOverride = true;
                }
            } else {
                $gotDelta = true;
            }
        }

        if ($gotOverride && $gotDelta) {
            $this->addError(
                'Entries replacing the inherited value ("=") cannot be combined'
                . ' with entries adding to ("+") or removing from ("-") it.'
                . ' Please remove the "=" entries or switch them over'
            );

            return false;
        }

        return true;
    }

    /**
     * We do not want one message per entry
     *
     * @codingStandardsIgnoreStart
     */
    protected function _getErrorMessages()
    {
        return $this->_errorMessages;
        // @codingStandardsIgnoreEnd
    }

    /**
     * @codingStandardsIgnoreStart
     */
    protected function _filterValue(&$value, &$key)
    {
        // @codingStandardsIgnoreEnd
        if (is_array($value)) {
            $value = array_filter($value, 'strlen');
        } elseif (is_string($value) && !strlen($value)) {
            $value = null;
        }

        parent::_filterValue($value, $key);
    }

    public function isValid($value, $context = null)
    {
        if ($value === null) {
            $value = [];
        }

        $value = array_filter($value, 'strlen');
        $this->setValue($value);
        if ($this->isRequired() && empty($value)) {
            // TODO: translate
            $this->addError('You are required to choose at least one element');
            return false;
        }

        if (! $this->entryOperatorsAreValid($value, $context)) {
            return false;
        }

        if ($this->hasErrors()) {
            return false;
        }

        return parent::isValid($value, $context);
    }
}
