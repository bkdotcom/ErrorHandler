<?php

namespace bdk\ErrorHandlerTests;

use bdk\ErrorHandler\Error;
use bdk\ErrorHandler\Plugin\Emailer;
use bdk\PubSub\Manager as EventManager;

/**
 * Test Emailer
 *
 * @covers \bdk\ErrorHandler\AbstractComponent
 * @covers \bdk\ErrorHandler\Plugin\Emailer
 * @covers \bdk\ErrorHandler\Plugin\Stats
 * @covers \bdk\ErrorHandler\Plugin\StatsStoreFile
 */
class EmailerTest extends TestBase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->errorHandler->setCfg('enableEmailer', true);
        $this->errorHandler->stats->flush();
    }

    public function testConstruct()
    {
        $_SERVER['SERVER_ADMIN'] = 'foo@bar.com';
        $emailer = new Emailer();
        $this->assertSame($_SERVER['SERVER_ADMIN'], $emailer->getCfg('emailTo'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testEmailOnError()
    {
        $errorVals = array(
            'type' => E_WARNING,
            'message' => 'Division by zero',
            'file' => __FILE__,
            'line' => __LINE__,
        );
        $onError = function (Error $error) {
            $error['vars'] = array(
                'foo' => 'bar',
            );
        };
        $cfg = $this->errorHandler->emailer->getCfg();
        $this->errorHandler->setCfg('onError', $onError);
        for ($i = 1; $i <= 3; $i++) {
            // clear errors so that each time we raise the error it appears to be the first time
            $this->errorHandler->setData('errors', array());
            $error = $this->raiseError($errorVals);
            if ($i === 1) {
                $cfg = $this->errorHandler->emailer->getCfg();
                $this->assertSame(0, $error['stats']['email']['countSince']);
                $this->assertSame($cfg['emailTo'], $this->emailInfo['to']);
                $this->assertSame(
                    'Error: ' . \implode(' ', $_SERVER['argv']) . ': ' . $errorVals['message'],
                    $this->emailInfo['subject']
                );
                $this->assertStringMatchesFormat(
                    'datetime: %s' . "\n"
                    . 'type: ' . $errorVals['type'] . ' (Warning)' . "\n"
                    . 'message: ' . $errorVals['message'] . "\n"
                    . 'file: ' . $errorVals['file'] . "\n"
                    . 'line: ' . $errorVals['line'] . "\n"
                    . "\n"
                    . 'backtrace: array(' . "\n"
                    . '%a'
                    . ')',
                    $this->emailInfo['body']
                );
                $this->assertSame('From: php@test.com', $this->emailInfo['addHeadersStr']);
                $this->emailInfo = array();
            } elseif ($i === 2) {
                $this->assertSame(1, $error['stats']['email']['countSince']);
                $this->assertSame(array(), $this->emailInfo);

                /*
                    Update the data so that the next time it appearas the error hasn't occured for a while
                */
                $hash = $error['hash'];
                $time6 = \strtotime('-6 hours');
                $dataStoreRef = new \ReflectionProperty($this->errorHandler->stats, 'dataStore');
                $dataStoreRef->setAccessible(true);
                $dataStore = $dataStoreRef->getValue($this->errorHandler->stats);
                $dataRef = new \ReflectionProperty($dataStore, 'data');
                $dataRef->setAccessible(true);
                $data = $dataRef->getValue($dataStore);
                $data['errors'][$hash] = \array_merge($data['errors'][$hash], array(
                    'email' => array(
                        'countSince' => 123,
                        'timestamp' => $time6,
                    ),
                ));
                $dataRef->setValue($dataStore, $data);
            } elseif ($i === 3) {
                $this->assertStringContainsString('Error has occurred 123 times since last email (' . \date($cfg['dateTimeFmt'], $time6) . ')', $this->emailInfo['body']);
            }
        }

        $statsError = \array_merge(array(
            'info' => array(
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ),
        ), $error['stats']);
        \ksort($statsError);
        $statsFound = $this->errorHandler->stats->find($error);
        \ksort($statsFound);
        $this->assertSame($statsError, $statsFound);
        $this->errorHandler->setCfg('onError', null);
    }

    public function testEmailOnErrorNotCli()
    {
        $errorVals = array(
            'type' => E_WARNING,
            'message' => 'You are doing it wrong',
            'file' => __FILE__,
            'line' => __LINE__,
        );
        $serverParamsRef = new \ReflectionProperty('bdk\\ErrorHandler\\Plugin\\Emailer', 'serverParams');
        $serverParamsRef->setAccessible(true);
        $serverParamsWas = $serverParamsRef->getValue($this->errorHandler->emailer);
        $serverParams = $serverParamsWas;
        $serverParams['argv'] = null;
        $serverParams = \array_merge($serverParams, array(
            'HTTP_HOST' => 'www.test.com',
            'HTTP_REFERER' => 'www.github.com/bkdotcom/ErrorHandler',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_URI' => '/errorHandler/test',
            'SERVER_NAME' => 'test.com',
        ));
        $serverParamsRef->setValue($this->errorHandler->emailer, $serverParams);
        $_POST = array(
            'foo' => 'bar',
        );
        $this->raiseError($errorVals);
        $serverParamsRef->setValue($this->errorHandler->emailer, $serverParamsWas);
        $_POST = array();
        $this->assertSame('Website Error: test.com: You are doing it wrong', $this->emailInfo['subject']);
        $this->assertStringMatchesFormat(
            'datetime: %s' . "\n"
            . 'type: ' . $errorVals['type'] . ' (Warning)' . "\n"
            . 'message: ' . $errorVals['message'] . "\n"
            . 'file: ' . $errorVals['file'] . "\n"
            . 'line: ' . $errorVals['line'] . "\n"
            . 'remote_addr: ' . $serverParams['REMOTE_ADDR'] . "\n"
            . 'http_host: ' . $serverParams['HTTP_HOST'] . "\n"
            . 'referer: ' . $serverParams['HTTP_REFERER'] . "\n"
            . 'request_uri: ' . $serverParams['REQUEST_URI'] . "\n"
            . 'post params: array (' . "\n"
            . '  \'foo\' => \'bar\',' . "\n"
            . ')' . "\n"
            . "\n"
            . 'backtrace: array(' . "\n"
            . '%a'
            . ')',
            $this->emailInfo['body']
        );
    }

    public function testEmailOnErrorCustomDumper()
    {
        $errorVals = array(
            'type' => E_WARNING,
            'message' => 'Danger Will Robinson',
            'file' => __FILE__,
            'line' => __LINE__,
        );
        $this->errorHandler->emailer->setCfg('emailBacktraceDumper', array($this, 'backtraceDumper'));
        $cfg = $this->errorHandler->emailer->getCfg();
        $this->raiseError($errorVals);
        $this->assertSame(array(
            'to' => $cfg['emailTo'],
            'subject' => 'Error: ' . \implode(' ', $_SERVER['argv']) . ': ' . $errorVals['message'],
            'body' => 'datetime: ' . \date($cfg['dateTimeFmt']) . "\n"
                . 'type: ' . $errorVals['type'] . ' (Warning)' . "\n"
                . 'message: ' . $errorVals['message'] . "\n"
                . 'file: ' . $errorVals['file'] . "\n"
                . 'line: ' . $errorVals['line'] . "\n"
                . "\n"
                . 'backtrace: custom!',
            'addHeadersStr' => 'From: php@test.com',
        ), $this->emailInfo);
        $this->errorHandler->emailer->setCfg('emailBacktraceDumper', null);
    }

    public function testEmailOnErrorNoBacktrace()
    {
        $onError = function (Error $error) {
            // set backtrace to a single frame
            $backtraceRef = new \ReflectionProperty($error, 'backtrace');
            $backtraceRef->setAccessible(true);
            $backtraceRef->setValue($error, array(
                array(
                    'file' => __FILE__,
                    'line' => __LINE__,
                )
            ));
        };
        $this->errorHandler->setCfg('onError', $onError);
        $errorVals = array(
            'type' => E_WARNING,
            'message' => 'Division by zero',
            'file' => __FILE__,
            'line' => __LINE__,
        );
        $this->raiseError($errorVals);
        $this->errorHandler->setCfg('onError', null);
        $this->assertStringContainsString('no backtrace', $this->emailInfo['body']);
    }

    public function testNoEmailOnThrow()
    {
        $errorVals = array(
            'type' => E_WARNING,
            'message' => 'Some error',
            'file' => __FILE__,
            'line' => __LINE__,
        );
        $this->errorHandler->setCfg('errorThrow', E_WARNING);
        $e = null;
        try {
            $this->raiseError($errorVals);
        } catch (\ErrorException $e) {
        }
        $this->assertNotNull($e);
        $this->assertSame(array(), $this->emailInfo);
        $this->errorHandler->setCfg('errorThrow', 0);
    }

    public function testOnShutdownThrottleSummaryFalse()
    {
        $this->errorHandler->setCfg('emailer', array(
            'emailThrottledSummary' => false,
        ));
        $this->errorHandler->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN);
        $this->assertSame(array(), $this->emailInfo);
        $this->errorHandler->setCfg('emailer', array(
            'emailThrottledSummary' => true,
        ));
    }

    /**
     * Test no error occurred, so no stats obj
     *
     * @return void
     */
    public function testOnShutdownNoStats()
    {
        $statsRef = new \ReflectionProperty($this->errorHandler->emailer, 'stats');
        $statsRef->setAccessible(true);
        $statsWas = $statsRef->getValue($this->errorHandler->emailer);
        $statsRef->setValue($this->errorHandler->emailer, null);
        $this->errorHandler->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN);
        $this->assertSame(array(), $this->emailInfo);
        $statsRef->setValue($this->errorHandler->emailer, $statsWas);
    }

    public function testOnShutdownNoSummaryErrors()
    {
        $this->errorHandler->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN);
        $this->assertSame(array(), $this->emailInfo);
    }

    public function testOnShutdownGarbageCollectedError()
    {
        $dataStoreRef = new \ReflectionProperty($this->errorHandler->stats, 'dataStore');
        $dataStoreRef->setAccessible(true);
        $dataStore = $dataStoreRef->getValue($this->errorHandler->stats);
        $dataRef = new \ReflectionProperty($dataStore, 'data');
        $dataRef->setAccessible(true);
        $data = $dataRef->getValue($dataStore);
        $ts24 = \strtotime('-24 hours');
        $tsNow = \time();
        $data['tsGarbageCollection'] = \strtotime('-12 hours');
        $data['errors']['errorhash1'] = array(
            // this error will remain
            'info' => array(
                'type' => E_WARNING,
                'message'  => 'error message 1',
                'file'    => '/path/to/file.php',
                'line'    => 42,
            ),
            'tsAdded' => $ts24,
            'tsLastOccur' => $tsNow,
            'count' => 22,
            'email' => array(
                'countSince' => 6,
                'timestamp' => $tsNow,
            ),
        );
        $data['errors']['errorhash2'] = array(
            // this error will get garbage collected
            'info' => array(
                'type' => E_WARNING,
                'message'  => 'error message 2',
                'file'    => '/path/to/file.php',
                'line'    => 42,
            ),
            'tsAdded' => $ts24,
            'tsLastOccur' => $ts24,
            'count' => 222,
            'email' => array(
                'countSince' => 123,
                'timestamp' => $ts24,
            ),
        );
        $dataRef->setValue($dataStore, $data);
        $dataWriteRef = new \ReflectionMethod($dataStore, 'dataWrite');
        $dataWriteRef->setAccessible(true);
        $dataWriteRef->invoke($dataStore);
        $this->errorHandler->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN);
        $this->assertNotEmpty($this->errorHandler->stats->find('errorhash1'));
        $this->assertEmpty($this->errorHandler->stats->find('errorhash2'));
        $cfg = $this->errorHandler->getCfg('emailer');
        $this->assertSame(array(
            'to' => $cfg['emailTo'],
            'subject' => 'Server Errors: ' . \implode(' ', $_SERVER['argv']),
            'body' => 'File: /path/to/file.php' . "\n"
                . 'Line: 42' . "\n"
                . 'Error: Warning: error message 2' . "\n"
                . 'Has occured 123 times since ' . \date($this->errorHandler->emailer->getCfg('dateTimeFmt'), $ts24) . "\n"
                . '' . "\n",
            'addHeadersStr' => 'From: php@test.com',
        ), $this->emailInfo);
        $this->emailInfo = array();
    }

    public function testDataWrite()
    {
        $statsFileNew = __DIR__ . '/statData/stats.json';
        $this->errorHandler->setCfg(array(
            'stats' => array(
                'errorStatsFile' => $statsFileNew,
            ),
        ));
        $this->raiseError(array(
            'type' => E_WARNING,
            'message' => 'statData dir should be created',
        ));
        $contents = \file_get_contents($statsFileNew);
        \unlink($statsFileNew);
        \rmdir(\dirname($statsFileNew));
        $this->assertNotEmpty($contents);
    }

    public function testDataWriteFail()
    {
        $errorLogWas = \ini_get('error_log');
        $logFile = __DIR__ . '/error_log.txt';
        \ini_set('error_log', $logFile);

        $statsFileNew = __DIR__ . '/statData/stats.json';
        \unlink($statsFileNew);
        \chmod(\dirname($statsFileNew), '0555');
        $this->errorHandler->setCfg(array(
            'stats' => array(
                'errorStatsFile' => $statsFileNew,
            ),
        ));
        $this->raiseError(array(
            'type' => E_WARNING,
            'message' => 'statData dir should be created',
        ));
        $logFileContents = \file_get_contents($logFile);

        \ini_set('error_log', $errorLogWas);
        \unlink($logFile);
        \chmod(\dirname($statsFileNew), '0777');
        // \rmdir(\dirname($statsFileNew));

        $this->assertStringContainsString(
            'bdk\ErrorHandler\Plugin\StatsStoreFile::dataWrite: error writing data to ' . $statsFileNew,
            $logFileContents
        );
    }

    public function testPostSetCfg()
    {
        $fileNew = __DIR__ . '/statStore.json';
        \file_put_contents($fileNew, \json_encode(array(
            'errors' => array(
                'notarealhash' => array(
                    'info' => array(),
                    'foo' => 'bar',
                ),
            )
        ), JSON_PRETTY_PRINT));
        $this->errorHandler->setCfg(array(
            'stats' => array(
                'errorStatsFile' => $fileNew,
            ),
        ));
        $stats = $this->errorHandler->stats->find('notarealhash');
        \unlink($fileNew);
        $this->assertSame(array(
            'info' => array(),
            'foo' => 'bar',
        ), $stats);
        $this->errorHandler->stats->setCfg(array(
            'errorStatsFile' => __DIR__ . '/../src/Plugin/error_stats.json',
        ));
    }

    public function backtraceDumper($backtrace)
    {
        return 'custom!';
    }
}
