<?php

namespace Tests\Icinga\Module\Director\Web\Form;

use Icinga\Module\Director\Test\BaseTestCase;
use Icinga\Module\Director\Web\Form\IplElement\ExtensibleSetElement;

class ExtensibleSetElementTest extends BaseTestCase
{
    public function testSetsWithoutOperatorsAreLeftAlone()
    {
        $this->assertNull(
            ExtensibleSetElement::operatorsAlignedWith(['a', 'b'], null)
        );
    }

    public function testOperatorsAreKeyedLikeTheirEntries()
    {
        $values = [1 => 'b', 2 => 'c'];

        $this->assertEquals(
            [1 => '+', 2 => '-'],
            ExtensibleSetElement::operatorsAlignedWith($values, ['=', '+', '-'])
        );
    }

    public function testMissingOperatorsFallBackToTheDefault()
    {
        $this->assertEquals(
            ['+', '=', '='],
            ExtensibleSetElement::operatorsAlignedWith(['a', 'b', 'c'], ['+'])
        );
    }
}
