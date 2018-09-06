<?php

class TestBase extends \PHPUnit\Framework\TestCase
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
            $this->errorHandler = \bdk\ErrorHandler::getInstance();
        }
        if (!$this->errorHandler) {
            $eventManager = new \bdk\PubSub\Manager();
            $this->errorHandler = new bdk\ErrorHandler($eventManager);
            self::$errorEmailer = new bdk\ErrorHandler\ErrorEmailer();
            $this->errorHandler->eventManager->subscribe('errorHandler.error', array(self::$errorEmailer, 'onErrorHighPri'), PHP_INT_MAX);
            $this->errorHandler->eventManager->subscribe('errorHandler.error', array(self::$errorEmailer, 'onErrorLowPri'), PHP_INT_MAX * -1);
            $this->errorHandler->eventManager->subscribe('errorHandler.error', function (\bdk\PubSub\Event $event) {
                if (self::$allowError) {
                    $event['continueToNormal'] = false;
                    $event['continueToPrevHandler'] = false;
                    return;
                }
                $event['continueToPrevHandler'] = true;
                throw new \PHPUnit\Framework\Exception($event['message'], 500);
            });
        }
        self::$errorEmailer->setCfg('emailTo', null);
        $this->errorHandler->setData('errors', array());
        $this->errorHandler->setData('errorCaller', array());
        $this->errorHandler->setData('lastError', null);
    }
}
