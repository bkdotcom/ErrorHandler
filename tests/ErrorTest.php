<?php

namespace bdk\ErrorHandlerTests;

use bdk\ErrorHandler\Error;

/**
 * PHPUnit tests
 *
 * @covers \bdk\ErrorHandler
 * @covers \bdk\ErrorHandler\AbstractErrorHandler
 * @covers \bdk\ErrorHandler\Error
 */
class ErrorTest extends TestBase // extends DebugTestFramework
{
    public function testConstruct()
    {
        $this->errorHandler->setErrorCaller(array(
            'file' => '/path/to/file.php',
            'line' => 42,
        ));
        $error = new Error($this->errorHandler, array(
            'type' => E_NOTICE,
            'message' => 'some notice',
            'file' => __FILE__,
            'line' => __LINE__,
        ));
        $this->assertSame(
            array(
                'file' => '/path/to/file.php',
                'line' => 42,
            ),
            array(
                'file' => $error['file'],
                'line' => $error['line'],
            )
        );
    }

    public function testMissingValThrowsException()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Error values must include: type, message, file, & line');
        new Error($this->errorHandler, array(
            'type' => E_NOTICE,
            'message' => 'some notice',
        ));
    }

    public function testInvalidTypeThrowsException()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('invalid error type specified');
        new Error($this->errorHandler, $this->randoErrorVals(false, array('type' => E_ALL)));
    }

    public function testInvalidVARSThrowsException()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Error vars must be an array');
        new Error($this->errorHandler, $this->randoErrorVals(false, array('vars' => null)));
    }

    public function testAsException()
    {
        $exception = new \Exception('exception notice!');
        $error = new Error($this->errorHandler, array(
            'type' => E_NOTICE,
            'message' => 'some notice',
            'file' => __FILE__,
            'line' => __LINE__,
            'exception' => $exception,
        ));
        $this->assertSame($exception, $error->asException());
    }

    public function testGetMessage()
    {
        $msgOrig = 'this was totally expected - <a href="https://github.com/bkdotcom/ErrorHandler/">more info</a>';
        $expectText = 'this was totally expected - more info';
        $expectHtml = 'this was totally expected - <a target="phpRef" href="https://github.com/bkdotcom/ErrorHandler/">more info</a>';
        $expectHtmlEscaped = \htmlspecialchars($msgOrig);

        \ini_set('html_errors', 0);
        $error = new Error($this->errorHandler, array(
            'type' => E_NOTICE,
            'message' => $msgOrig,
            'file' => __FILE__,
            'line' => __LINE__,
        ));
        $this->assertSame($msgOrig, $error['message']);
        $this->assertSame($expectHtmlEscaped, $error->getMessageHtml());
        $this->assertSame($expectText, $error->getMessageText());

        \ini_set('html_errors', 1);
        $error = new Error($this->errorHandler, array(
            'type' => E_NOTICE,
            'message' => $msgOrig,
            'file' => __FILE__,
            'line' => __LINE__,
        ));
        $this->assertSame($expectHtml, $error['message']);
        $this->assertSame($expectHtml, $error->getMessageHtml());
        $this->assertSame($expectText, $error->getMessageText());

        \ini_set('html_errors', 0);
    }

    public function testGetTraceParseError()
    {
        if (\class_exists('ParseError') === false) {
            $this->markTestSkipped('ParseError class does not available');
        }
        $exception = new \ParseError('Parse Error!');
        $error = new Error($this->errorHandler, array(
            'type' => E_PARSE,
            'message' => 'parse error',
            'file' => __FILE__,
            'line' => __LINE__,
            'exception' => $exception,
        ));
        $this->assertNull($error->getTrace());
    }

    public function testGetTrace()
    {
        $exception = new \Exception('exceptional error');
        $line = __LINE__ - 1;
        $error = new Error($this->errorHandler, array(
            'type' => E_WARNING,
            'message' => 'dang',
            'file' => __FILE__,
            'line' => __LINE__,
            'exception' => $exception,
        ));
        $trace = $error->getTrace();
        $this->assertSame(array(
            'args' => array(),
            'evalLine' => null,
            'file' => __FILE__,
            'line' => $line,
        ), $trace[0]);
    }

    public function testGetOffsetContext()
    {
        $line = __LINE__;
        $error = new Error($this->errorHandler, array(
            'type' => E_WARNING,
            'message' => 'dang',
            'file' => __FILE__,
            'line' => __LINE__,
        ));
        $context = $error['context'];
        $linesExpect = array(
            $line++ => '        $line = __LINE__;' . "\n",
            $line++ => '        $error = new Error($this->errorHandler, array(' . "\n",
            $line++ => '            \'type\' => E_WARNING,' . "\n",
            $line++ => '            \'message\' => \'dang\',' . "\n",
            $line++ => '            \'file\' => __FILE__,' . "\n",
            $line++ => '            \'line\' => __LINE__,' . "\n",
            $line++ => '        ));' . "\n",
            $line++ => '        $context = $error[\'context\'];' . "\n",
        );
        $this->assertCount(13, $context);
        $this->assertSame($linesExpect, \array_intersect_assoc($context, $linesExpect));
    }

    public function testLog()
    {
        $errorLogWas = \ini_get('error_log');
        $file = __DIR__ . '/error_log.txt';
        \ini_set('error_log', $file);
        $error = new Error($this->errorHandler, array(
            'type' => E_WARNING,
            'message' => 'dang',
            'file' => __FILE__,
            'line' => __LINE__,
        ));
        $error->log();
        $logContents = \file_get_contents($file);
        \unlink($file);
        \ini_set('error_log', $errorLogWas);
        $this->assertStringContainsString('PHP Warning: dang in ' . __FILE__ . ' on line ' . $error['line'], $logContents);
    }

    public function testPrevNotSuppressed()
    {
        $errorVals = array(
            'type' => E_WARNING,
            'message' => 'dang',
            'file' => __FILE__,
            'line' => __LINE__,
        );
        $this->raiseError($errorVals);
        $error = $this->raiseError($errorVals);
        $this->assertFalse($error['isSuppressed']);
    }

    public function testTypeStr()
    {
        $this->assertSame('User Error', Error::typeStr(E_USER_ERROR));
        $this->assertSame('', Error::typeStr('bogus'));
    }
}
