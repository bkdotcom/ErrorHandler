<?php

namespace bdk\ErrorHandlerTests;

use bdk\ErrorHandler;
use bdk\ErrorHandler\Error;
use bdk\ErrorHandlerTests\Polyfill\AssertionTrait;
use bdk\PubSub\Manager as EventManager;
use PHPUnit\Framework\TestCase;

/**
 *
 */
class TestBase extends TestCase
{
    use AssertionTrait;

    public static $allowError = false;
    public $errorHandler = null;
    protected $emailInfo = array();

    /**
     * setUp is executed before each test
     *
     * @return void
     */
    public function setUp(): void
    {
        self::$allowError = false;
        $this->errorHandler = ErrorHandler::getInstance();
        if (!$this->errorHandler) {
            $eventManager = new EventManager();
            $this->errorHandler = new ErrorHandler($eventManager);
            $this->errorHandler->eventManager->subscribe(ErrorHandler::EVENT_ERROR, function (Error $error) {
                if (self::$allowError) {
                    $error['continueToPrevHandler'] = false;
                    return;
                }
                $error['continueToPrevHandler'] = true;
                $error['throw'] = true;
            });
        }
        $this->errorHandler->setCfg(array(
            'enableStats' => true,
            'emailer' => array(
                'emailTo' => 'test@email.com', // need an email address to email to!
                'emailFrom' => 'php@test.com',
                'emailFunc' => array($this, 'emailMock'),
            ),
            'onEUserError' => 'continue',
            'errorThrow' => 0,
        ));
        $this->errorHandler->register();
        $this->errorHandler->setData('errors', array());
        $this->errorHandler->setData('errorCaller', array());
        $this->errorHandler->setData('lastErrors', array());
    }

    public function emailMock($to, $subject, $body, $addHeadersStr)
    {
        $this->emailInfo = array(
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'addHeadersStr' => $addHeadersStr,
        );
    }

    protected function randoErrorVals($asList = false, $vals = array())
    {
        $vals = \array_merge(array(
            'type' => E_USER_ERROR,
            'message' => 'Some error ' . \uniqid('', true),
            'file' => __FILE__,
            'line' => __LINE__,
            'vars' => array('foo' => 'bar'),
        ), $vals);
        return $asList
            ? \array_values($vals)
            : $vals;
    }

    protected function raiseError($vals = array(), $suppress = false)
    {
        self::$allowError = true;
        $errorReportingWas = $suppress
            ? \error_reporting(0)
            : \error_reporting();
        $callable = null;
        $vals = \array_merge(array(
            'type' => E_NOTICE,
            'message' => 'default error message',
            'file' => '/path/to/file.php',
            'line' => 42,
        ), $vals);
        $addVals = \array_diff_key($vals, \array_flip(array('type','message','file','line')));
        if ($addVals) {
            $callable = function (Error $error) use ($addVals) {
                foreach ($addVals as $k => $v) {
                    $error[$k] = $v;
                }
            };
            $this->errorHandler->eventManager->subscribe(ErrorHandler::EVENT_ERROR, $callable);
        }
        $return = $this->errorHandler->handleError($vals['type'], $vals['message'], $vals['file'], $vals['line']);
        if ($callable) {
            $this->errorHandler->eventManager->unsubscribe(ErrorHandler::EVENT_ERROR, $callable);
        }
        \error_reporting($errorReportingWas);
        $error = $this->errorHandler->getLastError(true);
        if ($error) {
            $error['return'] = $return;
        }
        return $error;
    }
}
