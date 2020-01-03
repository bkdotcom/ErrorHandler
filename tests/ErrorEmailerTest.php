<?php

/**
 * Test ErrorEmailer
 */
class ErrorEmailerTest extends TestBase
{

    private $emailCalledCount = 0;
    private $expectedSubject = '';

    /**
     * Test
     *
     * @return void
     */
    public function testEmailOnError()
    {
        parent::$allowError = true;

        self::$errorEmailer->throttleDataClear();
        self::$errorEmailer->setCfg(array(
            'emailTo' => 'test@email.com', // need an email address to email to!
            // 'collect' => false,     // individual emails only sent if not collecting
            // 'output' => false,      // email only sent if not outputing
            'emailFunc' => array($this, 'emailMock'),
        ));

        $this->expectedSubject = 'Error: ' . implode(' ', $_SERVER['argv']) . ': Division by zero';
        for ($i = 1; $i <= 2; $i++) {
            1 / 0; // warning
            if ($i == 1) {
                $this->assertSame(1, $this->emailCalledCount);
            } elseif ($i == 2) {
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
            . 'file: %s/tests/' . basename(__FILE__) . "\n"
            . 'line: %d' . "\n"
            . "\n"
            . 'backtrace: array(' . "\n"
            . '%a'
            . ')',
            $body
        );
    }
}
