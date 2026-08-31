<?php

namespace Icinga\Module\Director\CustomVariable;

use Icinga\Module\Director\Db;
use Icinga\Module\Director\IcingaConfig\IcingaConfigHelper as c;
use Icinga\Module\Director\IcingaConfig\IcingaLegacyConfigHelper as c1;
use Icinga\Module\Director\IcingaConfig\IcingaConfigRenderer;
use Icinga\Module\Director\Objects\IcingaObject;
use Countable;
use Exception;
use InvalidArgumentException;
use Iterator;

class CustomVariables implements Iterator, Countable, IcingaConfigRenderer
{
    /** @var CustomVariable[] */
    protected $storedVars = array();

    /** @var CustomVariable[]  */
    protected $vars = array();

    protected $modified = false;

    private $position = 0;

    private $overrideKeyName;

    protected $idx = array();

    protected static $allTables = array(
        'icinga_command_var',
        'icinga_host_var',
        'icinga_notification_var',
        'icinga_service_set_var',
        'icinga_service_var',
        'icinga_user_var',
    );

    public static function countAll($varname, Db $connection)
    {
        $db = $connection->getDbAdapter();
        $parts = array();
        $where = $db->quoteInto('varname = ?', $varname);
        foreach (static::$allTables as $table) {
            $parts[] = "SELECT COUNT(*) as cnt FROM $table WHERE $where";
        }

        $sub = implode(' UNION ALL ', $parts);
        $query = "SELECT SUM(sub.cnt) AS cnt FROM ($sub) sub";

        return (int) $db->fetchOne($query);
    }

    public static function deleteAll($varname, Db $connection)
    {
        $db = $connection->getDbAdapter();
        $where = $db->quoteInto('varname = ?', $varname);
        foreach (static::$allTables as $table) {
            $db->delete($table, $where);
        }
    }

    public static function renameAll($oldname, $newname, Db $connection)
    {
        $db = $connection->getDbAdapter();
        $where = $db->quoteInto('varname = ?', $oldname);
        foreach (static::$allTables as $table) {
            $db->update($table, ['varname' => $newname], $where);
        }
    }

    #[\ReturnTypeWillChange]
    public function count()
    {
        $count = 0;
        foreach ($this->vars as $var) {
            if (! $var->hasBeenDeleted()) {
                $count++;
            }
        }

        return $count;
    }

    #[\ReturnTypeWillChange]
    public function rewind()
    {
        $this->position = 0;
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        if (! $this->valid()) {
            return null;
        }

        return $this->vars[$this->idx[$this->position]];
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->idx[$this->position];
    }

    #[\ReturnTypeWillChange]
    public function next()
    {
        ++$this->position;
    }

    #[\ReturnTypeWillChange]
    public function valid()
    {
        return array_key_exists($this->position, $this->idx);
    }

    /**
     * Generic setter
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return self
     */
    public function set($key, $value)
    {
        $key = (string) $key;
        $gotDelta = false;

        if ($value instanceof CustomVariable) {
            $value = clone($value);
            $gotDelta = true;
        } else {
            if ($value === null) {
                $this->__unset($key);
                return $this;
            }
            $value = CustomVariable::create($key, $value);
        }

        // Hint: isset($this->$key) wouldn't conflict with protected properties
        if ($this->__isset($key)) {
            // A plain value carries no delta of its own. An empty one is what a
            // delta variable ships in 'vars', so the current delta survives it -
            // that is what makes 'vars' and 'var_deltas' order-independent, see
            // IcingaObject::applyVarDeltas(). Anything else is an assignment,
            // and an assignment replaces what it inherits instead of extending
            if (! $gotDelta && $value instanceof CustomVariableArray) {
                $existing = $this->get($key);
                if ($existing instanceof CustomVariableArray) {
                    if (empty($value->getValue())) {
                        $value->setDbDeltas($existing->getDbDeltas());
                    } elseif ($existing->hasDelta()) {
                        $existing->setDeltas(null);
                    }
                }
            }

            if ($value->equalsIncludingDeltas($this->get($key))) {
                return $this;
            } else {
                if (get_class($this->vars[$key]) === get_class($value)) {
                    // Hint: both sides carry the very same class, but only the
                    //       instanceof tells a static analyzer that a delta
                    //       exists here
                    $current = $this->vars[$key];
                    $current->setValue($value->getValue());
                    if ($current instanceof CustomVariableArray && $value instanceof CustomVariableArray) {
                        $current->setDeltas($value->getDeltas());
                    }
                    $current->setModified();
                } else {
                    $this->vars[$key] = $value->setLoadedFromDb()->setModified();
                }
            }
        } else {
            $this->vars[$key] = $value->setModified();
        }

        $this->modified = true;
        $this->refreshIndex();

        return $this;
    }

    protected function refreshIndex()
    {
        $this->idx = array();
        ksort($this->vars);
        foreach ($this->vars as $name => $var) {
            if (! $var->hasBeenDeleted()) {
                $this->idx[] = $name;
            }
        }
    }

    public static function loadForStoredObject(IcingaObject $object)
    {
        $db    = $object->getDb();

        $query = $db->select()->from(
            array('v' => $object->getVarsTableName()),
            array(
                'v.varname',
                'v.varvalue',
                'v.format',
                'v.entry_deltas',
            )
        )->where(sprintf('v.%s = ?', $object->getVarsIdColumn()), $object->get('id'));

        $vars = new CustomVariables();
        foreach ($db->fetchAll($query) as $row) {
            $vars->vars[$row->varname] = CustomVariable::fromDbRow($row);
        }
        $vars->refreshIndex();
        $vars->setBeingLoadedFromDb();
        return $vars;
    }

    public static function forStoredRows($rows)
    {
        $vars = new CustomVariables();
        foreach ($rows as $row) {
            $vars->vars[$row->varname] = CustomVariable::fromDbRow($row);
        }
        $vars->refreshIndex();
        $vars->setBeingLoadedFromDb();

        return $vars;
    }

    public function storeToDb(IcingaObject $object)
    {
        $db            = $object->getDb();
        $table         = $object->getVarsTableName();
        $foreignColumn = $object->getVarsIdColumn();
        $foreignId     = $object->get('id');


        foreach ($this->vars as $var) {
            if ($var->isNew()) {
                $db->insert(
                    $table,
                    array(
                        $foreignColumn => $foreignId,
                        'varname'      => $var->getKey(),
                        'varvalue'     => $var->getDbValue(),
                        'format'       => $var->getDbFormat(),
                        'entry_deltas' => $var instanceof CustomVariableArray
                            ? $var->getDbDeltas()
                            : null
                    )
                );
                $var->setLoadedFromDb();
                continue;
            }

            $where = $db->quoteInto(sprintf('%s = ?', $foreignColumn), (int) $foreignId)
                   . $db->quoteInto(' AND varname = ?', $var->getKey());

            if ($var->hasBeenDeleted()) {
                $db->delete($table, $where);
            } elseif ($var->hasBeenModified()) {
                $db->update(
                    $table,
                    array(
                        'varvalue' => $var->getDbValue(),
                        'format'   => $var->getDbFormat(),
                        'entry_deltas' => $var instanceof CustomVariableArray
                            ? $var->getDbDeltas()
                            : null
                    ),
                    $where
                );
            }
        }

        $this->setBeingLoadedFromDb();
    }

    public function get($key)
    {
        if (array_key_exists($key, $this->vars)) {
            return $this->vars[$key];
        }

        return null;
    }

    /**
     * Extend an inherited array by the given entries
     *
     * The variable is created when it does not exist yet - a delta needs no own
     * value to work on, Icinga 2 treats a missing one as an empty array
     *
     * @param string $key
     * @param array  $values
     * @return self
     */
    public function addEntries($key, array $values)
    {
        return $this->modifyDelta($key, function (CustomVariableArray $var) use ($values) {
            $var->addEntries($values);
        });
    }

    /**
     * Shrink an inherited array by the given entries
     *
     * @param string $key
     * @param array  $values
     * @return self
     */
    public function removeEntries($key, array $values)
    {
        return $this->modifyDelta($key, function (CustomVariableArray $var) use ($values) {
            $var->removeEntries($values);
        });
    }

    /**
     * Take over a complete delta, null clears whatever is there
     *
     * Hint: silently ignores unknown variables and non-arrays when clearing.
     *       There is nothing to clear on them, and telling a caller that its
     *       "no delta here" is invalid would make every reset a special case
     *
     * @param string            $key
     * @param array|object|null $deltas Buckets 'add' and 'remove'
     * @return self
     */
    public function setDeltas($key, $deltas)
    {
        if ($deltas === null || $deltas === [] || $deltas === (object) []) {
            $key = (string) $key;
            $var = isset($this->vars[$key]) ? $this->vars[$key] : null;
            if ($var instanceof CustomVariableArray && $var->hasDelta()) {
                $var->setDeltas(null);
                $this->modified = true;
            }

            return $this;
        }

        return $this->modifyDelta($key, function (CustomVariableArray $var) use ($deltas) {
            $var->setDeltas($deltas);
        });
    }

    /**
     * @param string $key
     * @return array|null Buckets 'add' and 'remove', null when there is none
     */
    public function getDeltas($key)
    {
        $key = (string) $key;
        if (array_key_exists($key, $this->vars) && $this->vars[$key] instanceof CustomVariableArray) {
            return $this->vars[$key]->getDeltas();
        }

        return null;
    }

    /** @return array varname => delta, for the variables carrying one */
    public function getAllDeltas()
    {
        $deltas = [];
        foreach ($this->vars as $key => $var) {
            if ($var->hasBeenDeleted() || ! $var instanceof CustomVariableArray) {
                continue;
            }
            if ($var->hasDelta()) {
                $deltas[$key] = $var->getDeltas();
            }
        }
        ksort($deltas);

        return $deltas;
    }

    /**
     * Hand the array variable behind the given key to the given modifier
     *
     * @param string   $key
     * @param callable $modifier
     * @return self
     */
    protected function modifyDelta($key, callable $modifier)
    {
        $key = (string) $key;
        if (! array_key_exists($key, $this->vars)) {
            $this->vars[$key] = CustomVariable::create($key, [])->setModified();
            $this->refreshIndex();
        }

        $var = $this->vars[$key];
        if (! $var instanceof CustomVariableArray) {
            throw new InvalidArgumentException(sprintf(
                'Only array custom variables can extend an inherited value, "%s" is %s',
                $key,
                $var->getType()
            ));
        }

        $before = $var->getDbDeltas();
        $modifier($var);
        if ($var->getDbDeltas() !== $before) {
            $this->modified = true;
        }

        return $this;
    }

    public function hasBeenModified()
    {
        if ($this->modified) {
            return true;
        }

        foreach ($this->vars as $var) {
            if ($var->hasBeenModified()) {
                return true;
            }
        }

        return false;
    }

    public function setBeingLoadedFromDb()
    {
        $this->modified = false;
        $this->storedVars = array();
        foreach ($this->vars as $key => $var) {
            $this->storedVars[$key] = clone($var);
            $var->setUnmodified();
            $var->setLoadedFromDb();
        }

        return $this;
    }

    public function restoreStoredVar($key)
    {
        if (array_key_exists($key, $this->storedVars)) {
            $this->vars[$key] = clone($this->storedVars[$key]);
            $this->vars[$key]->setUnmodified();
            $this->recheckForModifications();
            $this->refreshIndex();
        } elseif (array_key_exists($key, $this->vars)) {
            unset($this->vars[$key]);
            $this->recheckForModifications();
            $this->refreshIndex();
        }
    }

    protected function recheckForModifications()
    {
        $this->modified = false;
        foreach ($this->vars as $var) {
            if ($var->hasBeenModified()) {
                $this->modified = true;

                return;
            }
        }
    }

    public function getOriginalVars()
    {
        return $this->storedVars;
    }

    public function flatten()
    {
        $flat = array();
        foreach ($this->vars as $key => $var) {
            $var->flatten($flat, $key);
        }

        return $flat;
    }

    public function checksum()
    {
        $sums = array();
        foreach ($this->vars as $key => $var) {
            $sums[] = $key . '=' . $var->checksum();
        }

        return sha1(implode('|', $sums), true);
    }

    public function setOverrideKeyName($name)
    {
        $this->overrideKeyName = $name;
        return $this;
    }

    /**
     * @param bool        $renderExpressions
     * @param object|null $inheritedVars Needed to not add entries twice
     * @return string
     */
    public function toConfigString($renderExpressions = false, $inheritedVars = null)
    {
        $out = '';

        foreach ($this as $key => $var) {
            // TODO: ctype_alnum + underscore?
            $out .= $this->renderSingleVar($key, $var, $renderExpressions, $inheritedVars);
        }

        return $out;
    }

    /** @return bool Whether we need inherited values, resolving them is expensive */
    public function needsInheritedValues()
    {
        foreach ($this->vars as $var) {
            if (! $var->hasBeenDeleted() && $var->hasDelta()) {
                return true;
            }
        }

        return false;
    }

    public function toLegacyConfigString()
    {
        $out = '';

        ksort($this->vars);
        foreach ($this->vars as $key => $var) {
            // TODO: ctype_alnum + underscore?
            // vars with ARGn will be handled by IcingaObject::renderLegacyCheck_command
            if (substr($key, 0, 3) == 'ARG') {
                continue;
            }

            switch ($type = $var->getType()) {
                case 'String':
                case 'Number':
                    # TODO: Make Prefetchable
                    $out .= c1::renderKeyValue(
                        '_' . $key,
                        $var->toLegacyConfigString()
                    );
                    break;
                default:
                    $out .= c1::renderKeyValue(
                        '# _' . $key,
                        sprintf('(unsupported: %s)', $type)
                    );
            }
        }

        if ($out !== '') {
            $out = "\n" . $out;
        }

        return $out;
    }

    /**
     * @param string $key
     * @param CustomVariable $var
     * @param bool $renderExpressions
     *
     * @return string
     */
    protected function renderSingleVar($key, $var, $renderExpressions = false, $inheritedVars = null)
    {
        if ($key === $this->overrideKeyName) {
            return c::renderKeyOperatorValue(
                $this->renderKeyName($key),
                '+=',
                $var->toConfigStringPrefetchable($renderExpressions)
            );
        }

        if ($var instanceof CustomVariableArray && $var->hasDelta()) {
            return $this->renderArrayDelta($key, $var, $renderExpressions, $inheritedVars);
        }

        return c::renderKeyValue(
            $this->renderKeyName($key),
            $var->toConfigStringPrefetchable($renderExpressions)
        );
    }

    /**
     * Render an array extending respectively shrinking an inherited one
     *
     * One line per bucket, so a variable both adding and removing gives
     * "vars.x += [ ... ]" plus "vars.x -= [ ... ]"
     *
     * @param string              $key
     * @param CustomVariableArray $var
     * @param bool                $renderExpressions
     * @param object|null         $inheritedVars
     * @return string
     */
    protected function renderArrayDelta($key, CustomVariableArray $var, $renderExpressions, $inheritedVars)
    {
        $inherited = null;
        if ($inheritedVars !== null && property_exists($inheritedVars, $key)) {
            $inherited = $inheritedVars->$key;
        }

        $out = '';
        $added = $var->getAddedValues($inherited);
        if (! empty($added)) {
            $out .= c::renderKeyOperatorValue(
                $this->renderKeyName($key),
                '+=',
                $var->renderValues($added, $renderExpressions)
            );
        }

        $removed = $var->getRemovedValues();
        if (! empty($removed)) {
            $out .= c::renderKeyOperatorValue(
                $this->renderKeyName($key),
                '-=',
                $var->renderValues($removed, $renderExpressions)
            );
        }

        return $out;
    }

    protected function renderKeyName($key)
    {
        return 'vars' . self::renderKeySuffix($key);
    }

    public static function renderKeySuffix($key)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/i', $key)) {
            return '.' . c::escapeIfReserved($key);
        } else {
            return '[' . c::renderString($key) . ']';
        }
    }

    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * Magic setter
     *
     * @param  string  $key  Key
     * @param  mixed   $val  Value
     *
     * @return void
     */
    public function __set($key, $val)
    {
        $this->set($key, $val);
    }

    /**
     * Magic isset check
     *
     * @param string $key
     *
     * @return boolean
     */
    public function __isset($key)
    {
        return array_key_exists($key, $this->vars);
    }

    /**
     * Magic unsetter
     *
     * @param string $key
     *
     * @return void
     */
    public function __unset($key)
    {
        if (! array_key_exists($key, $this->vars)) {
            return;
        }

        $this->vars[$key]->delete();
        $this->modified = true;

        $this->refreshIndex();
    }

    public function __toString()
    {
        try {
            return $this->toConfigString();
        } catch (Exception $e) {
            trigger_error($e);
            $previousHandler = set_exception_handler(
                function () {
                }
            );
            restore_error_handler();
            call_user_func($previousHandler, $e);
            die();
        }
    }
}
