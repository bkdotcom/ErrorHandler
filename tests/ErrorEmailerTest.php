<?php

namespace bdk\ErrorHandler\tests;

/**
 * Test ErrorEmailer
 */
class ErrorEmailerTest extends TestBase
{

    private $emailCalledCount = 0;
    private $expectedSubject = '';

    public function setUp()
    {
        parent::setUp();
        self::$errorEmailer->throttleDataClear();
        self::$errorEmailer->setCfg(array(
            'emailTo' => 'test@email.com', // need an email address to email to!
            'emailFunc' => array($this, 'emailMock'),
        ));
    }

    /**
     * tearDown is executed after each test
     *
     * @return void
     */
    public function tearDown()
    {
        parent::tearDown();
        self::$errorEmailer->setCfg(array(
            'emailTo' => null, // need an email address to email to!
            'emailFunc' => function ($toAddr, $subject, $body) {},
        ));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testEmailOnError()
    {
        parent::$allowError = true;
        $this->expectedSubject = 'Error: ' . \implode(' ', $_SERVER['argv']) . ': Division by zero';
        for ($i = 1; $i <= 2; $i++) {
            // calling handleError directly vs actual error (don't want actual error thrown)
            $this->errorHandler->handleError(E_WARNING, 'Division by zero', __FILE__, __LINE__); // warning
            if ($i === 1) {
                $this->assertSame(1, $this->emailCalledCount);
            } elseif ($i === 2) {
                $this->assertSame(1, $this->emailCalledCount);
            }
        }
    }

    public function emailMock($toAddr, $subject, $body)
    {
        $this->emailCalledCount ++;
        $this->assertSame(self::$errorEmailer->getCfg('emailTo'), $toAddr);
        $this->assertSame($this->expectedSubject, $subject);
        $this->assertStringMatchesFormat(
            'datetime: %s (%s)' . "\n"
            . 'errormsg: Division by zero' . "\n"
            . 'errortype: 2 (Warning)' . "\n"
            . 'file: %s/tests/' . \basename(__FILE__) . "\n"
            . 'line: %d' . "\n"
            . "\n"
            . 'backtrace: array(' . "\n"
            . '%a'
            . ')',
            $body
        );
    }
}
