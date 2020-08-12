<?php

namespace bdk\ErrorHandler\tests;

use bdk\ErrorHandler\Error;

/**
 * PHPUnit tests
 */
class ErrorHandlerTest extends TestBase // extends DebugTestFramework
{

    private $onErrorEvent;
    private $onErrorUpdate = array();

    public function setUp()
    {
        parent::setUp();
        // $eventManager = new \bdk\PubSub\Manager();
        // $this->errorHandler = new bdk\ErrorHandler($eventManager);
        $this->errorHandler->eventManager->subscribe('errorHandler.error', array($this, 'onError'));
    }

    /**
     * tearDown is executed after each test
     *
     * @return void
     */
    public function tearDown()
    {
        $this->onErrorEvent = null;
        $this->onErrorUpdate = array();
        $this->errorHandler->eventManager->unsubscribe('errorHandler.error', array($this, 'onError'));
        parent::tearDown();
    }

    public function onError(Error $error)
    {
        foreach ($this->onErrorUpdate as $k => $v) {
            if ($k === 'stopPropagation') {
                if ($v) {
                    $error->stopPropagation();
                }
                continue;
            }
            $error->setValue($k, $v);
        }
        $this->onErrorEvent = $error;
        $this->onErrorUpdate = array();
    }

    public function testAsException()
    {
        $error = new Error(
            $this->errorHandler,
            E_USER_WARNING,
            'errorException test',
            __FILE__,
            123
        );
        $exception = $error->asException();
        $this->assertInstanceOf('ErrorException', $exception);
        $this->assertSame($exception->getSeverity(), $error['type']);
        $this->assertSame($exception->getMessage(), $error['message']);
        $this->assertSame($exception->getFile(), $error['file']);
        $this->assertSame($exception->getLine(), $error['line']);
        $this->assertSame($exception->getTrace(), $error->getTrace());
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGet()
    {
        $this->assertSame(null, $this->errorHandler->get('lastError'));
        $this->assertSame(array(), $this->errorHandler->get('errors'));
        $this->assertSame(array(
            'errorCaller'   => array(),
            'errors'        => array(),
            'lastErrors'    => array(),
            'uncaughtException' => null,
        ), $this->errorHandler->get('data'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGetInstance()
    {
        $class = \get_class($this->errorHandler);
        $instance = $class::getInstance();
        $this->assertInstanceOf($class, $instance);
        $this->assertSame($this->errorHandler, $instance);
    }

    /**
     * Test fatal error handling as well as can be tested...
     *
     * @return void
     */
    public function testFatal()
    {
        self::$allowError = true;
        $error = array(
            'type' => E_ERROR,
            'file' => __FILE__,
            'line' => __LINE__,
            'message' => 'This is a bogus fatal error',
        );
        $errorValuesExpect = array(
            'type'      => $error['type'],      // int
            'typeStr'   => 'Fatal Error',       // friendly string version of 'type'
            'category'  => 'fatal',
            'message'   => $error['message'],
            'file'      => $error['file'],
            'line'      => $error['line'],
            'vars'      => array(),
            // 'backtrace' => array(), // only if xdebug is enabled
            'continueToNormal' => true,
            'exception' => null,  // non-null if error is uncaught-exception
            // 'hash'      => null,
            'isFirstOccur'  => true,
            'isSuppressed'  => false,
        );
        $callLine = __LINE__ + 1;
        $this->errorHandler->eventManager->publish('php.shutdown', null, array('error' => $error));
        $lastError = $this->errorHandler->get('lastError');
        $this->assertArraySubset($errorValuesExpect, $lastError->getValues());
        // test subscriber
        $this->assertInstanceOf('bdk\\PubSub\\Event', $this->onErrorEvent);
        $this->assertSame($this->errorHandler, $this->onErrorEvent->getSubject());
        $this->assertArraySubset($errorValuesExpect, $this->onErrorEvent->getValues());
        if (\extension_loaded('xdebug')) {
            /*
                passing the error in the php.shutdown event doesn't do anyting special to the backtrace
                for a true error, the first frame of the trace would be the file/line of the error
                here it's the file/line of the eventManager->publish
            */
            $backtrace = $this->onErrorEvent['backtrace'];
            $lines = \array_merge(array(null), \file($error['file']));
            $lines = \array_slice($lines, $callLine - 9, 19, true);
            $this->assertSame(array(
                'args' => array(),
                'evalLine' => null,
                'file' => $error['file'],
                'line' => $callLine,
                'context' => $lines,
            ), $backtrace[0]);
            $this->assertSame(__CLASS__ . '->' . __FUNCTION__, $backtrace[1]['function']);
        }
    }

    /**
     * Test
     *
     * @return void
     */
    public function testHandler()
    {
        parent::$allowError = true;
        $error = array(
            'type' => E_WARNING,
            'file' => __FILE__,
            'line' => __LINE__,
            'vars' => array('foo' => 'bar'),
            'message' => 'test warning',
        );
        $errorValuesExpect = array(
            'type'      => E_WARNING,                    // int
            'typeStr'   => 'Warning',   // friendly string version of 'type'
            'category'  => 'warning',
            'message'   => $error['message'],
            'file'      => $error['file'],
            'line'      => $error['line'],
            'vars'      => $error['vars'],
            // 'backtrace' => array(), // only for fatal type errors, and only if xdebug is enabled
            'continueToNormal' => true,
            'exception' => null,  // non-null if error is uncaught-exception
            // 'hash'      => null,
            'isFirstOccur'  => true,
            'isSuppressed'  => false,
        );
        $return = $this->errorHandler->handleError(
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line'],
            $error['vars']
        );
        $this->assertFalse($return);
        // test that last error works
        $lastError = $this->errorHandler->get('lastError');
        $this->assertArraySubset($errorValuesExpect, $lastError->getValues());
        // test subscriber
        $this->assertInstanceOf('bdk\\PubSub\\Event', $this->onErrorEvent);
        $this->assertSame($this->errorHandler, $this->onErrorEvent->getSubject());
        $this->assertArraySubset($errorValuesExpect, $this->onErrorEvent->getValues());
    }

    public function testHandleErrorWithAnonymousClass()
    {
        self::$allowError = true;
        $anonymous = new class () extends \stdClass {
        };
        $this->errorHandler->handleError(
            E_WARNING,
            'foo ' . \get_class($anonymous) . ' bar',
            'foo.php',
            12
        );
        $this->assertSame('foo stdClass@anonymous bar', $this->onErrorEvent['message']);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testRegister()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSet()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSetErrorCaller()
    {
        $this->setErrorCallerHelper();
        $errorCaller = $this->errorHandler->get('errorCaller');
        $this->assertSame(array(
            'file' => __FILE__,
            'line' => __LINE__ - 4,
        ), $errorCaller);
    }

    public function testToString()
    {
        if (PHP_VERSION_ID >= 70400) {
            $this->markTestSkipped('PHP 7.4 allows __toString to throw exceptions');
        }
        self::$allowError = true;
        $e = null;
        $exception = new \Exception('Foo');
        try {
            $obj = new Fixture\ToStringThrower($exception);
            (string) $obj; // Trigger $f->__toString()
        } catch (\Exception $e) {
        }
        $this->assertSame($exception, $e);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testUnregister()
    {
    }

    public function testUserError()
    {
        parent::$allowError = true;
        $error = array(
            'type' => E_USER_ERROR,
            'file' => __FILE__,
            'line' => __LINE__,
            'vars' => array('foo' => 'bar'),
            'message' => 'Oh noes!',
        );

        $errorHandler = $this->errorHandler;
        $callable = array($errorHandler, 'handleError');
        $errorParams = array(
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line'],
            $error['vars']
        );

        $errorHandler->setCfg('onEUserError', 'continue');

        $return = \call_user_func_array($callable, \array_replace($errorParams, array(3 => __LINE__)));
        $this->assertTrue($return);

        /*
        $this->onErrorUpdate = array('continueToNormal' => false);
        $return = \call_user_func_array($callable, \array_replace($errorParams, array(3 => __LINE__)));
        $this->assertTrue($return);
        */

        $errorHandler->setCfg('onEUserError', 'log');

        $errorHandler->setCfg('errorFactory', function ($handler, $errType, $errMsg, $file, $line, $vars) {
            $error = $this->getMockBuilder('\\bdk\\ErrorHandler\\Error')->setConstructorArgs(array(
                $handler, $errType, $errMsg, $file, $line, $vars
            ))
                 ->setMethods(['log'])
                 ->getMock();
            $error->expects($this->once())
                ->method('log');
            return $error;
        });
        $return = \call_user_func_array($callable, \array_replace($errorParams, array(3 => __LINE__)));
        $this->assertTrue($return);
        $errorHandler->setCfg('errorFactory', array($errorHandler, 'errorFactory'));

        $this->onErrorUpdate = array('stopPropagation' => true);
        $errorHandler->setCfg('errorFactory', function ($handler, $errType, $errMsg, $file, $line, $vars) {
            $error = $this->getMockBuilder('\\bdk\\ErrorHandler\\Error')->setConstructorArgs(array(
                $handler, $errType, $errMsg, $file, $line, $vars
            ))
                 ->setMethods(['log'])
                 ->getMock();
            $error->expects($this->never())
                ->method('log');
            return $error;
        });
        $return = \call_user_func_array($callable, \array_replace($errorParams, array(3 => __LINE__)));
        $this->assertTrue($return);
        $errorHandler->setCfg('errorFactory', array($errorHandler, 'errorFactory'));

        $errorHandler->setCfg('onEUserError', 'normal');

        $return = \call_user_func_array($callable, \array_replace($errorParams, array(3 => __LINE__)));
        $this->assertFalse($return);

        // $this->onErrorUpdate = array('continueToNormal' => true);
        // $return = \call_user_func_array($callable, \array_replace($errorParams, array(3 => __LINE__)));
        // $this->assertFalse($return);


        $errorHandler->setCfg('onEUserError', null);

        $return = \call_user_func_array($callable, \array_replace($errorParams, array(3 => __LINE__)));
        $this->assertFalse($return);

        $this->onErrorUpdate = array('continueToNormal' => false);
        $return = \call_user_func_array($callable, \array_replace($errorParams, array(3 => __LINE__)));
        $this->assertTrue($return);
    }

    private function setErrorCallerHelper()
    {
        $this->errorHandler->setErrorCaller();
    }
}
