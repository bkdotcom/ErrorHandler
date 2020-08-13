<?php

namespace bdk\ErrorHandlerTests;

use bdk\ErrorHandler;
use bdk\ErrorHandler\Error;
use bdk\ErrorHandler\ErrorEmailer;
use bdk\PubSub\Manager;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\TestCase;

class TestBase extends TestCase
{

    public static $allowError = false;
    public $errorHandler = null;
    public static $errorEmailer = null;

    /**
     * setUp is executed before each test
     *
     * @return void
     */
    public function setUp()
    {
        self::$allowError = false;
        if (!$this->errorHandler) {
            $this->errorHandler = ErrorHandler::getInstance();
        }
        if (!$this->errorHandler) {
            $eventManager = new Manager();
            $this->errorHandler = new ErrorHandler($eventManager);
            self::$errorEmailer = new ErrorEmailer();
            $this->errorHandler->eventManager->subscribe('errorHandler.error', array(self::$errorEmailer, 'onErrorHighPri'), PHP_INT_MAX);
            $this->errorHandler->eventManager->subscribe('errorHandler.error', array(self::$errorEmailer, 'onErrorLowPri'), PHP_INT_MAX * -1);
            $this->errorHandler->eventManager->subscribe('errorHandler.error', function (Error $error) {
                if (self::$allowError) {
                    // $error['continueToNormal'] = false;
                    $error['continueToPrevHandler'] = false;
                    return;
                }
                $error['continueToPrevHandler'] = true;
                throw new Exception($error['message'], 500);
            });
        }
        $this->errorHandler->register();
        $this->errorHandler->setCfg(array(
            'emailTo' => null,
            'onEUserError' => 'continue',
            'errorThrow' => 0,
        ));
        $this->errorHandler->setData('errors', array());
        $this->errorHandler->setData('errorCaller', array());
        $this->errorHandler->setData('lastErrors', array());
    }
}
