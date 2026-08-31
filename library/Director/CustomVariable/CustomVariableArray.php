<?php

namespace Icinga\Module\Director\CustomVariable;

use Icinga\Module\Director\IcingaConfig\IcingaConfigHelper as c;
use Icinga\Module\Director\IcingaConfig\IcingaLegacyConfigHelper as c1;
use InvalidArgumentException;

class CustomVariableArray extends CustomVariable
{
    /** @var  CustomVariable[] */
    protected $value;

    /**
     * Entries added to an inherited array, plain values
     *
     * @var array
     */
    protected $added = [];

    /**
     * Entries removed from an inherited array, plain values
     *
     * @var array
     */
    protected $removed = [];

    public function equals(CustomVariable $var)
    {
        if (! $var instanceof CustomVariableArray) {
            return false;
        }

        return $var->getDbValue() === $this->getDbValue();
    }

    public function equalsIncludingDeltas(CustomVariable $var)
    {
        if (! $var instanceof CustomVariableArray) {
            return false;
        }

        return $this->equals($var) && $var->getDeltas() === $this->getDeltas();
    }

    public function getValue()
    {
        $ret = array();
        foreach ($this->value as $var) {
            $ret[] = $var->getValue();
        }

        return $ret;
    }

    public function getDbValue()
    {
        return json_encode($this->getValue());
    }

    public function getDbFormat()
    {
        return 'json';
    }

    public function setValue($value)
    {
        $new = array();

        foreach ($value as $k => $v) {
            $new[] = self::wantCustomVariable($k, $v);
        }

        $equals = true;
        if (is_array($this->value) && count($new) === count($this->value)) {
            foreach ($this->value as $k => $v) {
                if (! $new[$k]->equals($v)) {
                    $equals = false;
                    break;
                }
            }
        } else {
            $equals = false;
        }

        if (! $equals) {
            $this->value = $new;
            $this->setModified();
        }

        $this->deleted = false;

        return $this;
    }

    public function hasDelta()
    {
        return ! empty($this->added) || ! empty($this->removed);
    }

    /**
     * Extend an inherited array by the given entries
     *
     * @param array $values
     * @return $this
     */
    public function addEntries(array $values)
    {
        return $this->setDeltas([
            'add'    => array_merge($this->added, $values),
            'remove' => $this->removed,
        ]);
    }

    /**
     * Shrink an inherited array by the given entries
     *
     * @param array $values
     * @return $this
     */
    public function removeEntries(array $values)
    {
        return $this->setDeltas([
            'add'    => $this->added,
            'remove' => array_merge($this->removed, $values),
        ]);
    }

    /**
     * @param array|object|null $deltas Buckets 'add' and 'remove', null clears
     * @return $this
     */
    public function setDeltas($deltas)
    {
        $before = $this->getDeltas();
        $this->applyDeltas($deltas);
        if ($this->getDeltas() !== $before) {
            $this->setModified();
        }

        return $this;
    }

    /**
     * Like setDeltas(), but for values coming from our database
     *
     * @param string|null $deltas JSON, as stored in the entry_deltas column
     * @return $this
     */
    public function setDbDeltas($deltas)
    {
        if ($deltas === null || $deltas === '') {
            $this->applyDeltas(null);
        } else {
            $this->applyDeltas(json_decode($deltas));
        }

        return $this;
    }

    /**
     * Our delta, empty buckets left out
     *
     * @return array|null Null when we are a plain assignment
     */
    public function getDeltas()
    {
        if (! $this->hasDelta()) {
            return null;
        }

        $deltas = [];
        if (! empty($this->added)) {
            $deltas['add'] = $this->added;
        }
        if (! empty($this->removed)) {
            $deltas['remove'] = $this->removed;
        }

        return $deltas;
    }

    /** @return string|null JSON for the entry_deltas column, null when none */
    public function getDbDeltas()
    {
        $deltas = $this->getDeltas();

        return $deltas === null ? null : json_encode($deltas);
    }

    /**
     * Combine an inherited array with our entries, the way Icinga 2 would
     *
     * @param mixed $inherited The inherited value, null when there is none
     * @return array
     */
    public function applyTo($inherited)
    {
        if (! $this->hasDelta()) {
            return $this->getValue();
        }

        $result = is_array($inherited) ? array_values($inherited) : [];
        foreach ($this->added as $value) {
            if (! self::contains($result, $value)) {
                $result[] = $value;
            }
        }

        if (! empty($this->removed)) {
            $kept = [];
            foreach ($result as $value) {
                if (! self::contains($this->removed, $value)) {
                    $kept[] = $value;
                }
            }
            $result = $kept;
        }

        return $result;
    }

    /**
     * Entries to be added, without the ones already inherited
     *
     * Icinga 2 concatenates arrays without removing duplicates
     *
     * @param mixed $inherited
     * @return array
     */
    public function getAddedValues($inherited = null)
    {
        $result = [];
        foreach ($this->added as $value) {
            if (is_array($inherited) && self::contains($inherited, $value)) {
                continue;
            }

            $result[] = $value;
        }

        return $result;
    }

    /** @return array Entries to be removed */
    public function getRemovedValues()
    {
        return $this->removed;
    }

    public function checksum()
    {
        $deltas = $this->getDbDeltas();
        if ($deltas === null) {
            return parent::checksum();
        }

        return sha1($this->getKey() . $deltas . $this->toJson(), true);
    }

    public function flatten(array &$flat, $prefix)
    {
        // A delta has no own value, what it adds is the closest to one
        $values = $this->hasDelta() ? $this->added : $this->value;
        $idx = 0;
        foreach ($values as $value) {
            self::wantCustomVariable(null, $value)->flatten($flat, sprintf('%s[%d]', $prefix, $idx));
            $idx++;
        }
    }

    public function toConfigString($renderExpressions = false)
    {
        $parts = array();
        foreach ($this->value as $k => $v) {
            $parts[] = $v->toConfigString($renderExpressions);
        }

        return c::renderEscapedArray($parts);
    }

    /**
     * Render the given plain values as an Icinga 2 array
     *
     * @param array $values
     * @param bool  $renderExpressions
     * @return string
     */
    public function renderValues(array $values, $renderExpressions = false)
    {
        $parts = array();
        foreach ($values as $value) {
            $parts[] = self::wantCustomVariable(null, $value)->toConfigString($renderExpressions);
        }

        return c::renderEscapedArray($parts);
    }

    public function __clone()
    {
        foreach ($this->value as $key => $value) {
            $this->value[$key] = clone($value);
        }
    }

    public function toLegacyConfigString()
    {
        return c1::renderArray($this->value);
    }

    /**
     * Take over the given delta, without touching our modification state
     *
     * @param array|object|null $deltas
     */
    protected function applyDeltas($deltas)
    {
        if ($deltas === null) {
            $this->added = [];
            $this->removed = [];

            return;
        }

        $deltas = (array) $deltas;
        $unknown = array_diff(array_keys($deltas), ['add', 'remove']);
        if (! empty($unknown)) {
            throw new InvalidArgumentException(sprintf(
                'A custom variable delta knows "add" and "remove", got "%s"',
                implode('", "', $unknown)
            ));
        }

        $added = isset($deltas['add']) ? self::normalizeEntries($deltas['add']) : [];
        $removed = isset($deltas['remove']) ? self::normalizeEntries($deltas['remove']) : [];

        foreach ($added as $value) {
            if (self::contains($removed, $value)) {
                throw new InvalidArgumentException(sprintf(
                    'Custom variable "%s" cannot add and remove %s at once',
                    $this->getKey(),
                    json_encode($value)
                ));
            }
        }

        // Icinga 2 either replaces an array or extends it. A variable carrying
        // both would ship "vars.x = [ ... ]" and "vars.x += [ ... ]", and the
        // second line would then work on a value the first one just replaced
        if (! empty($added) || ! empty($removed)) {
            $this->assertHasNoOwnValue();
        }

        $this->added = $added;
        $this->removed = $removed;
    }

    /**
     * @throws InvalidArgumentException When we carry an own value
     */
    protected function assertHasNoOwnValue()
    {
        if (empty($this->value)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Custom variable "%s" is assigned %s, so it cannot also extend an'
            . ' inherited value. A variable is either assigned or extended',
            $this->getKey(),
            $this->getDbValue()
        ));
    }

    /**
     * Plain values, duplicates removed
     *
     * Order is kept, it decides where new entries show up in the rendered
     * array. Adding the very same entry twice must not duplicate it, Icinga 2
     * concatenates without deduplicating
     *
     * @param mixed $values
     * @return array
     */
    protected static function normalizeEntries($values)
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException(sprintf(
                'Custom variable delta entries must be given as an array, got %s',
                gettype($values)
            ));
        }

        $result = [];
        foreach ($values as $value) {
            if ($value instanceof CustomVariable) {
                $value = $value->getValue();
            }
            if (! self::contains($result, $value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * in_array(), but also dealing with arrays and objects as entries
     *
     * @param array $haystack
     * @param mixed $needle
     * @return bool
     */
    protected static function contains(array $haystack, $needle)
    {
        $needleIsPlain = is_scalar($needle) && ! is_bool($needle);

        foreach ($haystack as $candidate) {
            if ($candidate === $needle) {
                return true;
            }
            // "1" and 1 are the same entry for Icinga, booleans are not
            if ($needleIsPlain && is_scalar($candidate) && ! is_bool($candidate)) {
                if ((string) $candidate === (string) $needle) {
                    return true;
                }
            } elseif ((is_array($candidate) || is_object($candidate)) && $candidate == $needle) {
                return true;
            }
        }

        return false;
    }
}
