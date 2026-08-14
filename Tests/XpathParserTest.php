<?php

namespace Depage\XmlDb\Tests;

class XpathParserTest extends \PHPUnit\Framework\TestCase
{
    protected $parser;

    // {{{ setUp
    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new \Depage\XmlDb\XpathParser();
    }
    // }}}

    // {{{ testCleanOperator
    public function testCleanOperator()
    {
        $this->assertEquals('=', $this->parser->cleanOperator('='));
        $this->assertEquals('<', $this->parser->cleanOperator('<'));
        $this->assertEquals('>', $this->parser->cleanOperator('>'));
        $this->assertEquals('<=', $this->parser->cleanOperator('<='));
        $this->assertEquals('>=', $this->parser->cleanOperator('>='));
        $this->assertEquals('and', $this->parser->cleanOperator('and'));
        $this->assertEquals('or', $this->parser->cleanOperator('or'));
    }
    // }}}
    // {{{ testCleanOperatorFail
    public function testCleanOperatorFail()
    {
        $this->expectException(\Depage\XmlDb\Exceptions\XmlDbException::class);
        $this->expectExceptionMessage("Invalid XPath operator \"'\"");

        $this->parser->cleanOperator('\'');
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
