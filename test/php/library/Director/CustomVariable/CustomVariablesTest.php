<?php

namespace Tests\Icinga\Module\Director\CustomVariable;

use Icinga\Module\Director\CustomVariable\CustomVariable;
use Icinga\Module\Director\CustomVariable\CustomVariables;
use Icinga\Module\Director\Test\BaseTestCase;
use InvalidArgumentException;

class CustomVariablesTest extends BaseTestCase
{
    protected $indent = '    ';

    public function testWhetherSpecialKeyNames()
    {
        $vars = $this->newVars();
        $vars->bla = 'da';
        $vars->{'aBc'} = 'normal';
        $vars->{'a-0'} = 'special';
        $expected = $this->indentVarsList([
            'vars["a-0"] = "special"',
            'vars.aBc = "normal"',
            'vars.bla = "da"'
        ]);
        $this->assertEquals($expected, $vars->toConfigString());
    }

    public function testVarsCanBeUnsetAndSetAgain()
    {
        $vars = $this->newVars();
        $vars->one = 'two';
        unset($vars->one);
        $vars->one = 'three';

        $res = [];
        foreach ($vars as $k => $v) {
            $res[$k] = $v->getValue();
        }

        $this->assertEquals(['one' => 'three'], $res);
    }

    public function testNumericKeysAreRenderedWithArraySyntax()
    {
        $vars = $this->newVars();
        $vars->{'1'} = 1;
        $expected = $this->indentVarsList([
            'vars["1"] = 1'
        ]);

        $this->assertEquals(
            $expected,
            $vars->toConfigString(true)
        );
    }

    public function testVariablesToExpression()
    {
        $vars = $this->newVars();
        $vars->bla = 'da';
        $vars->abc = '$val$';
        $expected = $this->indentVarsList([
            'vars.abc = "$val$"',
            'vars.bla = "da"'
        ]);
        $this->assertEquals($expected, $vars->toConfigString(true));
    }

    public function testAddedEntriesAreRenderedAsAddition()
    {
        $vars = $this->newVars();
        $vars->plain = ['a'];
        $vars->addEntries('extended', ['b']);

        $expected = $this->indentVarsList([
            'vars.extended += [ "b" ]',
            'vars.plain = [ "a" ]'
        ]);

        $this->assertEquals($expected, $vars->toConfigString());
    }

    public function testAddingAndRemovingGivesTwoLines()
    {
        $vars = $this->newVars();
        $vars->addEntries('list', ['docker']);
        $vars->removeEntries('list', ['php-fpm']);

        $this->assertEquals(
            $this->indentVarsList([
                'vars.list += [ "docker" ]',
                'vars.list -= [ "php-fpm" ]'
            ]),
            $vars->toConfigString()
        );
    }

    public function testInheritedEntriesAreNotAddedTwice()
    {
        $vars = $this->newVars();
        $vars->addEntries('list', ['docker', 'nginx']);

        $inherited = (object) ['list' => ['nginx', 'redis']];

        $this->assertEquals(
            $this->indentVarsList(['vars.list += [ "docker" ]']),
            $vars->toConfigString(false, $inherited)
        );
    }

    public function testTheSameEntryIsAddedOnlyOnce()
    {
        $vars = $this->newVars();
        $vars->addEntries('list', ['docker', 'docker']);

        $this->assertEquals(
            $this->indentVarsList(['vars.list += [ "docker" ]']),
            $vars->toConfigString()
        );
    }

    public function testPlainArraysAreStillRenderedAsBefore()
    {
        $vars = $this->newVars();
        $vars->list = ['a', 'b'];

        $this->assertEquals(
            $this->indentVarsList(['vars.list = [ "a", "b" ]']),
            $vars->toConfigString()
        );
        $this->assertFalse($vars->needsInheritedValues());
    }

    /**
     * A delta variable ships an empty array in 'vars', as it has no own value.
     * Assigning that empty array back must not drop the delta - this is what
     * makes 'vars' and 'var_deltas' order-independent
     */
    public function testADeltaSurvivesTheEmptyValueItShips()
    {
        $vars = $this->newVars();
        $vars->addEntries('list', ['a']);
        $vars->list = [];

        $this->assertEquals(['add' => ['a']], $vars->getDeltas('list'));
    }

    public function testAssigningAValueDropsTheDelta()
    {
        $vars = $this->newVars();
        $vars->addEntries('list', ['a']);
        $vars->list = ['b'];

        $this->assertNull($vars->getDeltas('list'));
        $this->assertEquals(['b'], $vars->get('list')->getValue());
    }

    public function testAnAddedEntryModifiesVariables()
    {
        $vars = $this->newVars();
        $vars->list = ['a'];
        $vars->setBeingLoadedFromDb();
        $this->assertFalse($vars->hasBeenModified());

        $vars->addEntries('other', ['b']);
        $this->assertTrue($vars->hasBeenModified());
    }

    public function testAPlainArrayKeepsItsChecksum()
    {
        $vars = $this->newVars();
        $vars->list = ['a'];

        $this->assertEquals(
            sha1('list=' . json_encode(['a']), true),
            $vars->get('list')->checksum()
        );
        $this->assertNull($vars->get('list')->getDbDeltas());
    }

    public function testADeltaIsStoredAsJson()
    {
        $vars = $this->newVars();
        $vars->addEntries('list', ['b']);
        $vars->removeEntries('list', ['c']);

        $this->assertEquals(
            '{"add":["b"],"remove":["c"]}',
            $vars->get('list')->getDbDeltas()
        );
    }

    public function testAVariableIsEitherAssignedOrExtended()
    {
        $vars = $this->newVars();
        $vars->list = ['a'];

        $this->expectException(InvalidArgumentException::class);
        $vars->addEntries('list', ['b']);
    }

    public function testTheSameEntryCannotBeAddedAndRemoved()
    {
        $vars = $this->newVars();

        $this->expectException(InvalidArgumentException::class);
        $vars->setDeltas('list', ['add' => ['a'], 'remove' => ['a']]);
    }

    public function testOnlyArraysCanExtendAnInheritedValue()
    {
        $vars = $this->newVars();
        $vars->name = 'plain';

        $this->expectException(InvalidArgumentException::class);
        $vars->addEntries('name', ['a']);
    }

    /**
     * @dataProvider mergeExpectations
     * @param mixed      $inherited
     * @param array|null $assigned Null when the variable carries a delta
     * @param array|null $deltas
     * @param mixed      $expected
     */
    public function testValuesAreCombinedLikeIcingaWould($inherited, $assigned, $deltas, $expected)
    {
        $vars = $this->newVars();
        if ($assigned === null) {
            $vars->setDeltas('list', $deltas);
        } else {
            $vars->list = $assigned;
        }

        $this->assertEquals($expected, $vars->get('list')->applyTo($inherited));
    }

    public static function mergeExpectations()
    {
        return [
            'override wins'         => [['a'], ['b'], null, ['b']],
            'append to inherited'   => [['a'], null, ['add' => ['b']], ['a', 'b']],
            'append existing entry' => [['a'], null, ['add' => ['a']], ['a']],
            'append without parent' => [null, null, ['add' => ['b']], ['b']],
            'remove from inherited' => [['a', 'b'], null, ['remove' => ['b']], ['a']],
            'remove without parent' => [null, null, ['remove' => ['b']], []],
            'add and remove'        => [['a', 'b'], null, ['add' => ['c'], 'remove' => ['b']], ['a', 'c']],
        ];
    }

    protected function indentVarsList($vars)
    {
        return $this->indent . implode(
            "\n" . $this->indent,
            $vars
        ) . "\n";
    }

    protected function newVars()
    {
        return new CustomVariables();
    }
}
