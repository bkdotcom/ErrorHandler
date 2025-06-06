<?php

/**
 * @package   bdk\ErrorHandler
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2025 Brad Kent
 * @since     v3.2
 */

namespace bdk\ErrorHandler\Plugin;

use bdk\ErrorHandler;
use bdk\ErrorHandler\AbstractComponent;
use bdk\ErrorHandler\Error;
use bdk\PubSub\Event;
use bdk\PubSub\Manager as EventManager;
use bdk\PubSub\SubscriberInterface;

/**
 * Email error details on error
 *
 * Emails an error report on error and throttles said email so does not excessively send email
 *
 * @property bool $isCli
 */
class Emailer extends AbstractComponent implements SubscriberInterface
{
    const EVENT_EMAIL = 'errorHandler.email';

    /** @var \bdk\ErrorHandler\Plugin\Stats */
    private $stats = null;

    /** @var array<string,mixed> */
    protected $serverParams = array();

    /**
     * Constructor
     *
     * @param array $cfg config
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function __construct($cfg = array())
    {
        $this->serverParams = $_SERVER;
        $this->cfg = array(
            'dateTimeFmt' => 'Y-m-d H:i:s T',
            'emailBacktraceDumper' => null, // callable that receives backtrace array & returns string
            'emailFrom' => null,            // null = use php's default (php.ini: sendmail_from)
            'emailFunc' => 'mail',
            'emailMask' => E_ERROR | E_PARSE | E_COMPILE_ERROR | E_WARNING | E_USER_ERROR | E_USER_NOTICE,
            'emailMin' => 60 * 4,               // 0 = no throttle
            'emailThrottledSummary' => true,    // if errors have been throttled, should we email a summary email of throttled errors?
                                                //    (first occurrence of error is never throttled)
            'emailTo' => !empty($this->serverParams['SERVER_ADMIN'])
                ? $this->serverParams['SERVER_ADMIN']
                : null,
            'emailTraceMask' => E_ERROR | E_WARNING | E_USER_ERROR | E_USER_NOTICE,
        );
        $this->setCfg($cfg);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriptions()
    {
        return array(
            ErrorHandler::EVENT_ERROR => [
                ['onErrorHighPri', PHP_INT_MAX - 1],
                ['onErrorLowPri', PHP_INT_MAX * -1 + 1],
            ],
            EventManager::EVENT_PHP_SHUTDOWN => 'onPhpShutdown',
        );
    }

    /**
     * Initialize error's email (bool) value
     *
     * This function should come after stats added to error
     *
     * @param Error $error Error instance
     *
     * @return void
     */
    public function onErrorHighPri(Error $error)
    {
        $error['email'] = ($error['type'] & $this->cfg['emailMask'])
            && $error['isFirstOccur']
            && $this->cfg['emailTo'];
        $error['stats'] = \array_merge(array(
            'email' => array(
                'countSince' => 0,
                'emailedTo'  => null,
                'timestamp'  => null,
            ),
        ), $error['stats']);
        $tsCutoff = \time() - $this->cfg['emailMin'] * 60;
        if ($error['stats']['email']['timestamp'] > $tsCutoff) {
            // This error was recently emailed
            $error['stats']['email']['countSince']++;
        }
    }

    /**
     * Conditionally email error
     *
     * @param Error $error Error instance
     *
     * @return void
     */
    public function onErrorLowPri(Error $error)
    {
        if ($this->stats === null) {
            $this->stats = $error->getSubject()->stats;
        }
        if ($error['throw']) {
            $error['email'] = false;
        }
        if ($error['email'] && $this->cfg['emailMin'] > 0) {
            $tsCutoff = \time() - $this->cfg['emailMin'] * 60;
            $error['email'] = $error['stats']['email']['timestamp'] <= $tsCutoff;
        }
        if ($error['email']) {
            $this->emailErr($error);
        }
    }

    /**
     * Php shutdown event listener
     * Send a summary of errors that have not occurred recently, but have occurred since notification
     *
     * @return void
     */
    public function onPhpShutdown()
    {
        if ($this->cfg['emailThrottledSummary'] === false) {
            return;
        }
        if ($this->stats === null) {
            return;
        }
        $summaryErrors = $this->stats->getSummaryErrors();
        if (\count($summaryErrors) === 0) {
            return;
        }
        $this->email(
            $this->cfg['emailTo'],
            $this->isCli
                ? 'Server Errors: ' . \implode(' ', $this->serverParams['argv'])
                : 'Website Errors: ' . $this->serverParams['SERVER_NAME'],
            $this->buildBodySummary($summaryErrors)
        );
    }

    /**
     * Dump backtrace to plain-text
     *
     * @param array $trace Backtrace
     *
     * @return string
     */
    private function backtraceDumperDefault($trace)
    {
        $search = [
            ")\n\n",
        ];
        $replace = [
            ")\n",
        ];
        foreach ($trace as $i => $frame) {
            if (!empty($frame['context'])) {
                // remove newlines from context
                $frame[$i]['context'] = \array_map('rtrim', $frame['context']);
            }
        }
        $str = \print_r($trace, true);
        $str = \preg_replace('#\bArray\n\s*\(#', 'array(', $str);
        $str = \preg_replace('/\barray\s+\(\s+\)/s', 'array()', $str); // single-lineify empty arrays
        $str = \str_replace($search, $replace, $str);
        $str = \substr($str, 0, -1);
        return $str;
    }

    /**
     * Get formatted backtrace string for error
     *
     * @param Error $error Error instance
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
     */
    protected function backtraceStr(Error $error)
    {
        $backtrace = $error->getTrace() ?: $error->getSubject()->backtrace->get();
        if (empty($backtrace) || \count($backtrace) < 2) {
            return '';
        }
        if ($error['vars']) {
            $backtrace[0]['vars'] = $error['vars'];
        }
        return $this->cfg['emailBacktraceDumper']
            ? \call_user_func($this->cfg['emailBacktraceDumper'], $backtrace)
            : $this->backtraceDumperDefault($backtrace);
    }

    /**
     * Build error email body
     *
     * @param Error $error Error instance
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function buildBodyError(Error $error)
    {
        $emailBody = $this->buildBodyValues($error);
        if (!empty($_POST)) {
            $emailBody .= 'post params: ' . \var_export($_POST, true) . "\n";
        }
        if ($error['type'] & $this->cfg['emailTraceMask']) {
            $backtraceStr = $this->backtraceStr($error);
            $emailBody .= "\n" . ($backtraceStr
                ? 'backtrace: ' . $backtraceStr
                : 'no backtrace');
        }
        return $emailBody;
    }

    /**
     * Build string containing error values
     *
     * @param Error $error Error instance
     *
     * @return string
     */
    private function buildBodyValues(Error $error)
    {
        $string = \implode("\n", [
            'datetime: ' . \date($this->cfg['dateTimeFmt']),
            'type: ' . $error['type'] . ' (' . $error['typeStr'] . ')',
            'message: ' . $error->getMessageText(),
            'file: ' . $error['file'],
            'line: ' . $error['line'],
        ]) . "\n";
        if ($this->isCli === false) {
            $string .= \implode("\n", [
                'remote_addr: ' . $this->serverParams['REMOTE_ADDR'],
                'http_host: ' . $this->serverParams['HTTP_HOST'],
                'referer: ' . (isset($this->serverParams['HTTP_REFERER'])
                    ? $this->serverParams['HTTP_REFERER']
                    : 'null'),
                'request method: ' . $this->serverParams['REQUEST_METHOD'],
                'request uri: ' . $this->serverParams['REQUEST_URI'],
            ]) . "\n";
        }
        return $string;
    }

    /**
     * Build summary of errors that haven't occurred in a while
     *
     * @param array $errors errors to include in summary
     *
     * @return string
     */
    protected function buildBodySummary($errors)
    {
        $request = $this->isCli
            ? \implode(' ', $this->serverParams['argv'])
            : $this->serverParams['HTTP_HOST'] . $this->serverParams['REQUEST_URI'];

        $emailBody = 'This summary sent via ' . $request . "\n\n";

        foreach ($errors as $errStats) {
            $countSinceLine = isset($errStats['email'])
                ? \sprintf(
                    'Has occurred %s times since %s' . "\n",
                    $errStats['email']['countSince'],
                    \date($this->cfg['dateTimeFmt'], $errStats['email']['timestamp'])
                )
                : '';
            $info = $errStats['info'];
            $emailBody .= ''
                . 'File: ' . $info['file'] . "\n"
                . 'Line: ' . $info['line'] . "\n"
                . 'Error: ' . Error::typeStr($info['type']) . ': ' . $info['message'] . "\n"
                . $countSinceLine
                . "\n";
        }
        return $emailBody;
    }

    /**
     * Send an email
     *
     * @param string $toAddr  To
     * @param string $subject Subject
     * @param string $body    Body
     *
     * @return void
     */
    protected function email($toAddr, $subject, $body)
    {
        $addHeadersStr = '';
        $fromAddr = $this->cfg['emailFrom'];
        if ($fromAddr) {
            $addHeadersStr .= 'From: ' . $fromAddr;
        }
        $body = \str_replace("\x00", '\x00', $body);
        \call_user_func($this->cfg['emailFunc'], $toAddr, $subject, $body, $addHeadersStr);
    }

    /**
     * Email this error
     *
     * @param Error $error Error instance
     *
     * @return void
     */
    protected function emailErr(Error $error)
    {
        $countSince = $error['stats']['email']['countSince'];
        $emailBody = '';
        if (!empty($countSince)) {
            $dateTimePrev = \date($this->cfg['dateTimeFmt'], $error['stats']['email']['timestamp']) ?: '';
            $emailBody .= 'Error has occurred ' . $countSince . ' times since last email (' . $dateTimePrev . ').' . "\n\n";
        }
        $emailBody .= $this->buildBodyError($error);

        $values = $error->getSubject()->eventManager->publish(self::EVENT_EMAIL, new Event(
            $error,
            array(
                'body' => $emailBody,
                'subject' => $this->getSubject($error),
                'to' => $this->cfg['emailTo'],
            )
        ))->getValues();

        $this->email($values['to'], $values['subject'], $values['body']);

        $error['stats']['email']['emailedTo'] = $values['to'];
        $error['stats']['email']['timestamp'] = \time();
    }

    /**
     * Build email subject
     *
     * @param Error $error Error instance
     *
     * @return string
     */
    private function getSubject(Error $error)
    {
        $countSince = $error['stats']['email']['countSince'];
        $subject = $this->isCli
            ? 'Error: ' . \implode(' ', $this->serverParams['argv'])
            : 'Website Error: ' . $this->serverParams['SERVER_NAME'];
        $subject .= ': ' . $error->getMessageText() . ($countSince ? ' (' . $countSince . 'x)' : '');
        return $subject;
    }

    /**
     * Is script running from command line (or cron)?
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    protected function isCli()
    {
        $valsDefault = array(
            'argv' => null,
            'QUERY_STRING' => null,
        );
        $vals = \array_merge($valsDefault, \array_intersect_key($this->serverParams, $valsDefault));
        return $vals['argv'] && \implode('+', $vals['argv']) !== $vals['QUERY_STRING'];
    }
}
