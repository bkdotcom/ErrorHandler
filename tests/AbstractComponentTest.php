<?php

namespace bdk\ErrorHandlerTests;

use bdk\ErrorHandlerTests\Fixture\ExtendsComponent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \bdk\ErrorHandler\AbstractComponent
 */
class AbstractComponentTest extends TestCase
{
    public function setUp(): void
    {
        $this->obj = new ExtendsComponent();
    }

    public function testGetReadOnly()
    {
        $this->assertSame('bar', $this->obj->foo);
    }

    public function testGetUnavail()
    {
        $this->assertNull($this->obj->baz);
    }

    public function testGetCfgViaArray()
    {
        $this->assertSame(true, $this->obj->getCfg(array('doMagic')));
    }

    public function testGetCfgEmptyKey()
    {
        $this->assertSame(array(
            'doMagic' => true,
        ), $this->obj->getCfg());
    }

    public function testGetCfgUndefined()
    {
        $this->assertNull($this->obj->getCfg('what'));
    }
}
