<?php

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
            if ($k == 'stopPropagation') {
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
        $class = get_class($this->errorHandler);
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
            'continueToNormal' => false,   // set to false via DebugTestFramework error subscriber
            'exception' => null,  // non-null if error is uncaught-exception
            // 'hash'      => null,
            'isFirstOccur'  => true,
            'isSuppressed'  => false,
        );
        $this->errorHandler->eventManager->publish('php.shutdown', null, array('error' => $error));
        $lastError = $this->errorHandler->get('lastError');
        $this->assertArraySubset($errorValuesExpect, $lastError);
        // test subscriber
        $this->assertInstanceOf('bdk\\PubSub\\Event', $this->onErrorEvent);
        $this->assertSame($this->errorHandler, $this->onErrorEvent->getSubject());
        $this->assertArraySubset($errorValuesExpect, $this->onErrorEvent->getValues());
        if (extension_loaded('xdebug')) {
            $backtrace = $this->onErrorEvent['backtrace'];
            $lines = \array_merge(array(null), \file($error['file']));
            $lines = \array_slice($lines, $error['line'] - 9, 19, true);
            $this->assertSame(array(
                'file' => $error['file'],
                'line' => $error['line'],
                'args' => array(),
                'evalLine' => null,
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
            'message' => 'test warmomg',
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
            'continueToNormal' => false,   // set to false via DebugTestFramework error subscriber
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
        $this->assertTrue($return);
        $lastError = $this->errorHandler->get('lastError');
        $this->assertArraySubset($errorValuesExpect, $lastError->getValues());
        // test subscriber
        $this->assertInstanceOf('bdk\\PubSub\\Event', $this->onErrorEvent);
        $this->assertSame($this->errorHandler, $this->onErrorEvent->getSubject());
        $this->assertArraySubset($errorValuesExpect, $this->onErrorEvent->getValues());
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

    private function setErrorCallerHelper()
    {
        $this->errorHandler->setErrorCaller();
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
        $errorParams = array($error['type'], $error['message'], $error['file'], $error['line'], $error['vars']);

        $errorHandler->setCfg('onEUserError', 'continue');
        $this->onErrorUpdate = array('continueToNormal' => true);
        $return = call_user_func_array($callable, $errorParams);
        $this->assertTrue($return);
        $this->onErrorUpdate = array('continueToNormal' => false);
        $return = call_user_func_array($callable, $errorParams);
        $this->assertTrue($return);

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
        $this->onErrorUpdate = array('continueToNormal' => true);
        $return = call_user_func_array($callable, $errorParams);
        $this->assertTrue($return);
        $this->onErrorUpdate = array('continueToNormal' => false);
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
        $return = call_user_func_array($callable, $errorParams);
        $this->assertTrue($return);
        $errorHandler->setCfg('errorFactory', array($errorHandler, 'errorFactory'));

        $errorHandler->setCfg('onEUserError', 'normal');
        $this->onErrorUpdate = array('continueToNormal' => true);
        $return = call_user_func_array($callable, $errorParams);
        $this->assertFalse($return);
        $this->onErrorUpdate = array('continueToNormal' => false);
        $return = call_user_func_array($callable, $errorParams);
        $this->assertFalse($return);

        $errorHandler->setCfg('onEUserError', null);
        $this->onErrorUpdate = array('continueToNormal' => true);
        $return = call_user_func_array($callable, $errorParams);
        $this->assertFalse($return);
        $this->onErrorUpdate = array('continueToNormal' => false);
        $return = call_user_func_array($callable, $errorParams);
        $this->assertTrue($return);

        $errorHandler->unregister();
    }
}
