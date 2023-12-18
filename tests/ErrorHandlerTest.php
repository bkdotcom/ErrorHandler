<?php

namespace bdk\ErrorHandlerTests;

use bdk\ErrorHandler;
use bdk\ErrorHandler\Error;
use bdk\PubSub\Event;
use bdk\PubSub\Manager as EventManager;

/**
 * PHPUnit tests
 *
 * @covers \bdk\ErrorHandler
 * @covers \bdk\ErrorHandler\AbstractErrorHandler
 * @covers \bdk\ErrorHandler\Error
 */
class ErrorHandlerTest extends TestBase
{
    private $onErrorEvent;
    private $onErrorUpdate = array();

    public function setUp(): void
    {
        parent::setUp();
        $this->errorHandler->eventManager->subscribe(ErrorHandler::EVENT_ERROR, array($this, 'onError'));
        \set_error_handler(array($this, 'errorHandler'));
        \set_exception_handler(array($this, 'exceptionHandler'));  // exceptions won't make it here...   phpunit wraps tests in try/catch
        $this->errorHandler->register();
    }

    public static function setUpBeforeClass(): void
    {
        $errorHandler = ErrorHandler::getInstance();
        if (!$errorHandler) {
            return;
        }
        $emailerRef = new \ReflectionProperty('\\bdk\\ErrorHandler\\AbstractErrorHandler', 'emailer');
        $emailerRef->setAccessible(true);
        $emailer = $emailerRef->getValue($errorHandler);
        if ($emailer) {
            $errorHandler->eventManager->removeSubscriberInterface($emailer);
            $emailerRef->setValue($errorHandler, null);
        }
        $statsRef = new \ReflectionProperty('\\bdk\\ErrorHandler\\AbstractErrorHandler', 'stats');
        $statsRef->setAccessible(true);
        $stats = $statsRef->getValue($errorHandler);
        if ($stats) {
            $errorHandler->eventManager->removeSubscriberInterface($stats);
            $statsRef->setValue($errorHandler, null);
        }
    }

    /**
     * tearDown is executed after each test
     *
     * @return void
     */
    public function tearDown(): void
    {
        $this->onErrorEvent = null;
        $this->onErrorUpdate = array();
        $this->errorHandler->eventManager->unsubscribe(ErrorHandler::EVENT_ERROR, array($this, 'onError'));
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

    public function testConstructor()
    {
        $instanceRef = new \ReflectionProperty('bdk\\ErrorHandler', 'instance');
        $instanceRef->setAccessible(true);
        $instanceRef->setValue(null, null);
        $errorHandler = new ErrorHandler($this->errorHandler->eventManager);
        $instanceRef->setValue(null, $this->errorHandler);
        self::assertInstanceOf('bdk\\ErrorHandler', $errorHandler);
        $errorHandler->unregister();
    }

    public function testContinueToPrevHandler()
    {
        $this->errorHandler->unregister();
        // \set_error_handler(null);
        \restore_error_handler();
        $this->errorHandler->register();

        $error = $this->raiseError(array(
            'type' => E_ERROR,
            'message' => 'exception error',
            'file' => __FILE__,
            'line' => __LINE__,
            'continueToPrevHandler' => true,
        ));
        self::assertFalse($error['return']);
    }

    public function testErrorReporting()
    {
        $this->errorHandler->setCfg('errorReporting', 'system');
        self::assertSame(\error_reporting(), $this->errorHandler->errorReporting());
        \error_reporting('-1');
        self::assertSame(E_ALL | E_STRICT, $this->errorHandler->errorReporting());
        $this->errorHandler->setCfg('errorReporting', E_ALL | E_STRICT);
    }

    public function testAsException()
    {
        $error = new Error($this->errorHandler, array(
            'type' => E_USER_WARNING,
            'message' => 'errorException test',
            'file' => __FILE__,
            'line' => 123,
        ));
        $exception = $error->asException();
        self::assertInstanceOf('ErrorException', $exception);
        self::assertSame($exception->getSeverity(), $error['type']);
        self::assertSame($exception->getMessage(), $error['message']);
        self::assertSame($exception->getFile(), $error['file']);
        self::assertSame($exception->getLine(), $error['line']);
        self::assertSame($exception->getTrace(), $error->getTrace() ?: array());
    }

    public function testGetCfg()
    {
        self::assertSame(array(
            'continueToPrevHandler',
            'errorFactory',
            'errorReporting',
            'errorThrow',
            'onError',
            'onEUserError',
            'onFirstError',
            'suppressNever',
            'emailer',
            'enableEmailer',
            'enableStats',
            'stats',
        ), \array_keys($this->errorHandler->getCfg()));
    }

    public function testGet()
    {
        self::assertSame(null, $this->errorHandler->get('lastError'));
        self::assertSame(array(), $this->errorHandler->get('errors'));
        self::assertSame(array(
            'errorCaller'   => array(),
            'errors'        => array(),
            'lastErrors'    => array(),
            'uncaughtException' => null,
        ), $this->errorHandler->get('data'));
    }

    public function testGetMagic()
    {
        $propRef = new \ReflectionProperty('\\bdk\\ErrorHandler\\AbstractErrorHandler', 'backtrace');
        $propRef->setAccessible(true);
        $propRef->setValue($this->errorHandler, null);
        self::assertInstanceOf('\\bdk\\Backtrace', $this->errorHandler->backtrace);
        self::assertSame(null, $this->errorHandler->noSuchProperty);
    }

    public function testGetStats()
    {
        $stats = $this->errorHandler->stats;
        self::assertInstanceOf('\\bdk\ErrorHandler\\Plugin\\Stats', $stats);
    }

    public function testGetLastError()
    {
        $this->raiseError(array(
            'type' => E_NOTICE,
            'message' => 'some error',
            'file' => __FILE__,
            'line' => '111',
        ));
        $this->raiseError(array(
            'type' => E_NOTICE,
            'message' => 'some suppressed error',
            'file' => __FILE__,
            'line' => '222',
        ), true);
        $error = $this->errorHandler->getLastError();
        self::assertSame(
            array(
                'message' => 'some error',
                'line' => '111',
            ),
            array(
                'message' => $error['message'],
                'line' => $error['line'],
            )
        );
        $error = $this->errorHandler->getLastError(true);
        self::assertSame(
            array(
                'message' => 'some suppressed error',
                'line' => '222',
            ),
            array(
                'message' => $error['message'],
                'line' => $error['line'],
            )
        );
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
        self::assertInstanceOf($class, $instance);
        self::assertSame($this->errorHandler, $instance);
        $errorHandler = $class::getInstance(array(
            'ding' => 'dong',
        ));
        self::assertSame($errorHandler, $this->errorHandler);
        self::assertSame('dong', $errorHandler->getCfg('ding'));

        $instanceRef = new \ReflectionProperty($errorHandler, 'instance');
        $instanceRef->setAccessible(true);
        $instanceRef->setValue($errorHandler, null);
        self::assertFalse($class::getInstance());
        $instanceRef->setValue($errorHandler, $errorHandler);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testHandleError()
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
            'type'      => E_WARNING,   // int
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
        self::assertFalse($return);
        // test that last error works
        $lastError = $this->errorHandler->get('lastError');
        self::assertArraySubset($errorValuesExpect, $lastError->getValues());
        // test subscriber
        self::assertInstanceOf('bdk\\PubSub\\Event', $this->onErrorEvent);
        self::assertSame($this->errorHandler, $this->onErrorEvent->getSubject());
        self::assertArraySubset($errorValuesExpect, $this->onErrorEvent->getValues());
    }

    public function testHandleErrorNotHandled()
    {
        $this->errorHandler->setCfg('errorReporting', E_WARNING | E_USER_WARNING);
        $error = $this->raiseError(array(
            'type' => E_NOTICE,
            'message' => 'not handled',
            'file' => __FILE__,
            'line' => __LINE__,
        ));
        self::assertNull($error);
        $this->errorHandler->setCfg('errorReporting', E_ALL | E_NOTICE);
    }

    public function testHandleException()
    {
        $thrown = false;
        try {
            $exception = new \Exception('make an exception');
            $line = __LINE__ - 1;
            $this->errorHandler->handleException($exception);
        } catch (\Exception $e) {
            $thrown = true;
        }
        self::assertTrue($thrown);
        $error = $this->errorHandler->getLastError();
        self::assertSame(
            array(
                'type' => E_ERROR,
                'message' => 'Uncaught exception \'Exception\' with message make an exception',
                'file' => __FILE__,
                'line' => $line,
                'exception' => $exception,
            ),
            array(
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'exception' => $error['exception'],
            )
        );
    }

    public function testHandleExceptionNoPrev()
    {
        $errorLogWas = \ini_get('error_log');
        $file = __DIR__ . '/error_log.txt';
        \ini_set('error_log', $file);

        $this->errorHandler->unregister();
        // \set_exception_handler(null);
        \restore_exception_handler();
        $this->errorHandler->register();
        $exception = new \Exception('I am exceptional');
        $line = __LINE__ - 1;
        $this->errorHandler->setCfg('onError', function (Error $error) {
            $error['continueToNormal'] = true;
        });
        $this->errorHandler->handleException($exception);
        $this->errorHandler->setCfg('onError', null);
        $error = $this->errorHandler->getLastError();

        $logContents = \file_get_contents($file);
        \unlink($file);
        \ini_set('error_log', $errorLogWas);

        $expect = 'PHP Fatal Error: Uncaught exception \'Exception\' with message I am exceptional in ' . __FILE__ . ' on line ' . $line;
        self::assertStringContainsString($expect, $logContents);

        self::assertSame(
            array(
                'type' => E_ERROR,
                'message' => 'Uncaught exception \'Exception\' with message I am exceptional',
                'file' => __FILE__,
                'line' => $line,
                'exception' => $exception,
            ),
            array(
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'exception' => $error['exception'],
            )
        );
    }

    public function testHandleErrorWithAnonymousClass()
    {
        if (PHP_VERSION_ID < 70000) {
            $this->markTestSkipped('anonymous classes are a php 7.0 thing');
        }
        self::$allowError = true;
        $anonymous = require __DIR__ . '/Fixture/Anonymous.php';

        $this->raiseError(array(
            'type' => E_WARNING,
            'message' => 'foo ' . \get_class($anonymous) . ' bar',
            'file' => 'foo.bar',
            'line' => 12,
        ));
        self::assertSame('foo stdClass@anonymous bar', $this->onErrorEvent['message']);
    }

    public function testOnFirstError()
    {
        $firstError = array();
        $this->errorHandler->setCfg('onFirstError', function (Error $error) use (&$firstError) {
            $firstError = array(
                'type' => $error['type'],
                'message' => $error['message'],
                'line' => $error['line'],
                'file' => $error['file'],
            );
        });
        $errorVals = array(
            'type' => E_NOTICE,
            'message' => 'first error!',
            'line' => __LINE__,
            'file' => __FILE__,
        );
        $this->raiseError($errorVals);
        $this->errorHandler->setCfg('onFirstError', null);
        self::assertSame($firstError, $errorVals);
    }

    public function testOnShutdownNotRegistered()
    {
        $this->errorHandler->unregister();
        $event = $this->errorHandler->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN);
        self::assertNull($event['error']);
    }

    public function testOnShutdownNoError()
    {
        $event = $this->errorHandler->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN);
        self::assertNull($event['error']);
    }

    public function testOnShutdownErrorNotFatal()
    {
        $errorVals = array(
            'type' => E_NOTICE,
            'message' => 'meh',
            'file' => __FILE__,
            'line' => __LINE__,
        );
        $errorExpect = new Error($this->errorHandler, $errorVals);
        $event = $this->errorHandler->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN, null, array(
            'error' => $errorVals,
        ));
        self::assertEquals($errorExpect, $event['error']);
    }

    /**
     * Test fatal error handling as well as can be tested...
     *
     * @return void
     */
    public function testOnShutdownErrorFatal()
    {
        self::$allowError = true;
        $error = array(
            'type' => E_ERROR,
            'file' => __FILE__,
            'line' => __LINE__,
            'message' => 'This is a bogus fatal error',
            'vars' => array(
                'foo' => 'bar',
            ),
        );
        $errorValuesExpect = array(
            'type'      => $error['type'],      // int
            'typeStr'   => 'Fatal Error',       // friendly string version of 'type'
            'category'  => 'fatal',
            'message'   => $error['message'],
            'file'      => $error['file'],
            'line'      => $error['line'],
            'vars'      => $error['vars'],
            // 'backtrace' => array(), // only if xdebug is enabled
            'continueToNormal' => true,
            'exception' => null,  // non-null if error is uncaught-exception
            // 'hash'      => null,
            'isFirstOccur'  => true,
            'isSuppressed'  => false,
        );
        $callLine = __LINE__ + 1;
        $this->errorHandler->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN, null, array('error' => $error));
        $lastError = $this->errorHandler->get('lastError');
        self::assertArraySubset($errorValuesExpect, $lastError->getValues());
        // test subscriber
        self::assertInstanceOf('bdk\\PubSub\\Event', $this->onErrorEvent);
        self::assertSame($this->errorHandler, $this->onErrorEvent->getSubject());
        self::assertArraySubset($errorValuesExpect, $this->onErrorEvent->getValues());
        if (\extension_loaded('xdebug')) {
            /*
                passing the error in the php.shutdown event doesn't do anyting special to the backtrace
                for a true error, the first frame of the trace would be the file/line of the error
                here it's the file/line of the eventManager->publish
            */
            $backtrace = $this->onErrorEvent['backtrace'];
            $lines = \array_merge(array(null), \file($error['file']));
            $lines = \array_slice($lines, $callLine - 9, 19, true);
            self::assertSame(array(
                'evalLine' => null,
                'file' => $error['file'],
                'line' => $callLine,
                'context' => $lines,
            ), $backtrace[0]);
            self::assertSame(__CLASS__ . '->' . __FUNCTION__, $backtrace[1]['function']);
        }
    }

    public function testPostSetCfgStats()
    {
        $this->errorHandler->setCfg(array(
            'stats' => array(
                'foo' => 'bar',
            ),
        ));
        self::assertSame('bar', $this->errorHandler->stats->getCfg('foo'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testRegister()
    {
        \set_error_handler(array($this, 'errorHandler'));
        \set_exception_handler(array($this, 'exceptionHandler'));
        $this->errorHandler->register();
        self::assertSame(array($this, 'errorHandler'), $this->errorHandler->get('prevErrorHandler'));
        self::assertSame(array($this, 'exceptionHandler'), $this->errorHandler->get('prevExceptionHandler'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSetCfg()
    {
        $onErrorWas = $this->errorHandler->setCfg('onError', function (Error $error) {
        });
        self::assertNull($onErrorWas);
        $subscribers = $this->errorHandler->eventManager->getSubscribers('onError');
        $count1 = \count($subscribers);
        $onErrorWas = $this->errorHandler->setCfg('onError', function (Error $error) {
        });
        self::assertInstanceOf('Closure', $onErrorWas);
        $subscribers = $this->errorHandler->eventManager->getSubscribers('onError');
        $count2 = \count($subscribers);
        self::assertSame($count1, $count2);
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
        self::assertSame(array(
            'evalLine' => null,
            'file' => __FILE__,
            'line' => __LINE__ - 5,
        ), $errorCaller);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSetErrorCallerClear()
    {
        $this->errorHandler->setErrorCaller(array());
        $errorCaller = $this->errorHandler->get('errorCaller');
        self::assertSame(array(), $errorCaller);
    }

    public function testThrowErrorException()
    {
        try {
            self::$allowError = true;
            $this->errorHandler->setCfg('errorThrow', -1);
            $errorVals = array(
                'type' => E_USER_ERROR,
                'message' => 'This is a test',
                'file' => __FILE__,
                'line' => __LINE__,
            );
            $backtraceLine = __LINE__ + 1;
            $this->raiseError($errorVals);
            $this->fail('ErrorException expected');
        } catch (\ErrorException $e) {
            self::assertSame($errorVals, array(
                'type' => $e->getSeverity(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ));
            $trace = $e->getTrace();
            self::assertSame($errorVals['file'], $trace[1]['file']);
            self::assertSame($backtraceLine, $trace[1]['line']);
            PHP_VERSION_ID >= 70000
                ? self::assertSame('bdk\\ErrorHandlerTests\\ErrorHandlerTest->raiseError', $trace[1]['function'])
                : self::assertSame('bdk\\ErrorHandlerTests\\TestBase->raiseError', $trace[1]['function']);
            self::assertSame(__CLASS__ . '->' . __FUNCTION__, $trace[2]['function']);
        }
        $errorVals = array(
            'type' => E_NOTICE,
            'message' => 'suppressed error',
            'file' => __FILE__,
            'line' => 42,
        );
        $error = $this->raiseError($errorVals, true);
        self::assertsame(
            array(
                'isSuppressed' => true,
                'throw' => true,
            ),
            array(
                'isSuppressed' => $error['isSuppressed'],
                'throw' => $error['throw'],
            )
        ); // but not thrown because suppressed
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
        self::assertSame($exception, $e);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testUnregister()
    {
        $this->errorHandler->unregister();
        self::assertNull($this->errorHandler->get('prevErrorHandler'));
        self::assertNull($this->errorHandler->get('prevExceptionHandler'));
        self::assertSame(__CLASS__, \get_class($this->errorHandler->errorHandler[0]));
        self::assertSame('errorHandler', $this->errorHandler->errorHandler[1]);
        self::assertSame(__CLASS__, \get_class($this->errorHandler->exceptionHandler[0]));
        self::assertSame('exceptionHandler', $this->errorHandler->exceptionHandler[1]);
    }

    public function testOnUserErrorContinue()
    {
        parent::$allowError = true;
        $errorHandler = $this->errorHandler;
        $errorHandler->setCfg('onEUserError', 'continue');
        $handleErrorCallable = array($errorHandler, 'handleError');
        $return = \call_user_func_array($handleErrorCallable, $this->randoErrorVals(true));
        self::assertTrue($return);
    }

    public function testOnUserErrorLog()
    {
        parent::$allowError = true;
        $errorHandler = $this->errorHandler;
        $errorHandler->setCfg('onEUserError', 'log');
        $errorHandler->setCfg('errorFactory', function ($handler, $errType, $errMsg, $file, $line, $vars) {
            $errorMock = $this->getMockBuilder('\\bdk\\ErrorHandler\\Error')
                ->setConstructorArgs(array(
                    $handler,
                    array(
                        'type' => $errType,
                        'message' => $errMsg,
                        'file' => $file,
                        'line' => $line,
                        'vars' => $vars,
                    ),
                ))
                ->setMethods(['log'])
                ->getMock();
            $errorMock->expects($this->once())
                ->method('log');
            return $errorMock;
        });
        $handleErrorCallable = array($errorHandler, 'handleError');
        $return = \call_user_func_array($handleErrorCallable, $this->randoErrorVals(true));
        $errorHandler->setCfg('errorFactory', array($errorHandler, 'errorFactory'));
        self::assertTrue($return);
    }

    public function testOnUserErrorStopPropagation()
    {
        parent::$allowError = true;
        $errorHandler = $this->errorHandler;
        $this->onErrorUpdate = array('stopPropagation' => true);
        $errorHandler->setCfg('errorFactory', function ($handler, $errType, $errMsg, $file, $line, $vars) {
            $errorMock = $this->getMockBuilder('\\bdk\\ErrorHandler\\Error')
                ->setConstructorArgs(array(
                    $handler,
                    array(
                        'type' => $errType,
                        'message' => $errMsg,
                        'file' => $file,
                        'line' => $line,
                        'vars' => $vars,
                    ),
                ))
                ->setMethods(['log'])
                ->getMock();
            $errorMock->expects($this->never())
                ->method('log');
            return $errorMock;
        });
        $handleErrorCallable = array($errorHandler, 'handleError');
        $return = \call_user_func_array($handleErrorCallable, $this->randoErrorVals(true));
        $errorHandler->setCfg('errorFactory', array($errorHandler, 'errorFactory'));
        self::assertTrue($return);
    }

    public function testOnUserErrorNormal()
    {
        parent::$allowError = true;
        $errorHandler = $this->errorHandler;
        $errorHandler->setCfg('onEUserError', 'normal');
        $handleErrorCallable = array($errorHandler, 'handleError');
        $return = \call_user_func_array($handleErrorCallable, $this->randoErrorVals(true));
        self::assertFalse($return);
    }

    public function testOnUserErrorNull()
    {
        parent::$allowError = true;
        $errorHandler = $this->errorHandler;
        $errorHandler->setCfg('onEUserError', null);
        $handleErrorCallable = array($errorHandler, 'handleError');
        $return = \call_user_func_array($handleErrorCallable, $this->randoErrorVals(true));
        self::assertTrue($return);
    }

    public function testOnUserErrorContinueToNormalFalse()
    {
        parent::$allowError = true;
        $errorHandler = $this->errorHandler;
        $this->onErrorUpdate = array('continueToNormal' => false);
        $handleErrorCallable = array($errorHandler, 'handleError');
        $return = \call_user_func_array($handleErrorCallable, $this->randoErrorVals(true));
        self::assertTrue($return);
    }

    public function errorHandler($type, $message, $file, $line)
    {
        return true;
    }

    public function exceptionHandler(\Exception $exception)
    {
        // never called... tests are wrapped in try/catch
    }

    private function setErrorCallerHelper()
    {
        $this->errorHandler->setErrorCaller();
    }
}
