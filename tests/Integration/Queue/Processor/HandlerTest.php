<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue\Processor;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\QueuedTracking\Queue\Processor\Handler;
use Piwik\Plugins\QueuedTracking\tests\Framework\Mock\ForcedException;
use Piwik\Plugins\QueuedTracking\tests\Framework\Mock\Tracker;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\Tracker\RequestSet;
use Piwik\Tracker\Db as TrackerDb;
use Piwik\Tracker\Db\DbException;
use Piwik\Tracker\Request;

class TestHandler extends Handler
{
    public function getTransactionId()
    {
        return $this->transactionId;
    }
}

/**
 * @group QueuedTracking
 * @group HandlerTest
 * @group Plugins
 * @group Tracker
 */
class HandlerTest extends IntegrationTestCase
{
    /**
     * @var TestHandler
     */
    private $handler;

    /**
     * @var Tracker
     */
    private $tracker;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::createWebsite('2014-01-02 03:04:05');

        $this->handler = $this->createHandler();
        $this->tracker = new Tracker();

        $this->handler->init($this->tracker);
    }

    public function tearDown(): void
    {
        $this->handler->rollBack($this->tracker);

        parent::tearDown();
    }

    public function test_construct_shouldNotHaveAnErrorByDefault()
    {
        $handler = $this->createHandler();
        $this->assertFalse($handler->hasErrors());
    }

    public function test_init_shouldStartATransaction()
    {
        $this->assertNotEmpty($this->handler->getTransactionId());
        $this->assertIsString($this->handler->getTransactionId());
        $this->assertTrue(ctype_alnum($this->handler->getTransactionId()));
    }

    public function test_init_shouldBackUpTheNumberOfTrackedRequestsAndRestoreOnException()
    {
        $handler = $this->createHandler();

        $this->tracker->setCountOfLoggedRequests(11);

        $handler->init($this->tracker);

        // fake some logged requests
        $this->tracker->setCountOfLoggedRequests(39);

        $handler->rollBack($this->tracker);

        $this->assertSame(11, $this->tracker->getCountOfLoggedRequests());
    }

    public function test_onException_shouldMarkTheHandlerAsFaulty()
    {
        $this->handler->onException(new RequestSet(), new \Exception());

        $this->assertTrue($this->handler->hasErrors());
    }

    public function test_init_shouldResetAFaultyHandler()
    {
        $this->handler->onException(new RequestSet(), new \Exception());
        $this->handler->onException(new RequestSet(), new DbException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 1213));
        $this->assertTrue($this->handler->hasErrors());
        $this->assertTrue($this->handler->hasTransactionErrors());

        $this->handler->rollBack($this->tracker);
        $this->handler->init($this->tracker);
        $this->assertFalse($this->handler->hasErrors());
        $this->assertFalse($this->handler->hasTransactionErrors());
    }

    public function test_process_ShouldForwardTheRequestToTheTracker()
    {
        $this->handler->process($this->tracker, $this->buildRequestSet(7));

        $this->assertSame(7, $this->tracker->getCountOfLoggedRequests());
    }

    public function test_getRequestSetsToRetry_ShouldReturnAnEmptyArrayIfAllRequestSetsWereSuccessfullyProcessed()
    {
        $this->handler->process($this->tracker, $this->buildRequestSet(7));
        $this->handler->process($this->tracker, $this->buildRequestSet(7));
        $this->handler->process($this->tracker, $this->buildRequestSet(7));
        $this->handler->commit();

        $this->assertEquals(array(), $this->handler->getRequestSetsToRetry());
    }

    public function test_process_ShouldStopTrackingOnceThereWasAFaultyRequest()
    {
        try {
            $this->handler->process($this->tracker, $this->buildRequestSetContainingError(7, 4));
            $this->fail('An expected exception was not triggered');
        } catch (ForcedException $e) {
        }

        $this->assertSame(4, $this->tracker->getCountOfLoggedRequests());
    }

    public function test_process_ShouldIgnoreInvalidIdSiteRequests_LikeBulkTrackingPlugin()
    {
        $this->handler->process($this->tracker, $this->buildRequestSetContainingError(7, 4, $invalidSiteException = true));

        $this->assertSame(6, $this->tracker->getCountOfLoggedRequests());
    }

    public function test_onException_shouldRemoveAllInvalidRequestsFromValidRequests()
    {
        $requestSet = $this->buildRequestSetContainingError(7, 4);

        try {
            $this->handler->process($this->tracker, $requestSet);
            $this->fail('An expected exception was not triggered');
        } catch (ForcedException $e) {
            $this->handler->onException($requestSet, $e);
        }

        $this->assertSame(4, $requestSet->getNumberOfRequests());

        // verify correct ones kept
        $requests = $requestSet->getRequests();
        $this->assertEquals(array('idsite' => '1', 'index' => '0'), $requests[0]->getParams());
        $this->assertEquals(array('idsite' => '1', 'index' => '1'), $requests[1]->getParams());
        $this->assertEquals(array('idsite' => '1', 'index' => '2'), $requests[2]->getParams());
        $this->assertEquals(array('idsite' => '1', 'index' => '3'), $requests[3]->getParams());
    }

    public function testOnExceptionShouldNotKeepRequestsOfASetWhoseEnvironmentCouldNotBeRestored()
    {
        $requestSet = $this->buildRequestSet(5);
        $this->handler->process($this->tracker, $requestSet);

        $failingRequestSet = new class extends RequestSet {
            public function restoreEnvironment()
            {
                throw new ForcedException('forced exception');
            }
        };
        $failingRequestSet->setRequests($this->buildRequestSet(3)->getRequests());

        try {
            $this->handler->process($this->tracker, $failingRequestSet);
            $this->fail('An expected exception was not triggered');
        } catch (ForcedException $e) {
            $this->handler->onException($failingRequestSet, $e);
        }

        $this->assertEquals([$requestSet], $this->handler->getRequestSetsToRetry());
    }

    public function test_rollBack_shouldAddTheSuccessfullyProcessedRequestSetToAllValidRequestSets()
    {
        $requestSet1 = $this->buildRequestSet(5);
        $requestSet2 = $this->buildRequestSet(3);
        $requestSet3 = $this->buildRequestSet(1);
        $requestSet4 = $this->buildRequestSetContainingError(7, 4);
        $requestSet5 = $this->buildRequestSet(1);
        $requestSet6 = $this->buildRequestSet(0);
        $requestSet7 = $this->buildRequestSet(1);

        $this->handler->process($this->tracker, $requestSet1);
        $this->handler->process($this->tracker, $requestSet2);
        $this->handler->process($this->tracker, $requestSet3);

        try {
            $this->handler->process($this->tracker, $requestSet4);
            $this->fail('An expected exception was not triggered');
        } catch (ForcedException $e) {
            $this->handler->onException($requestSet4, $e);
        }

        $this->handler->process($this->tracker, $requestSet5);
        $this->handler->process($this->tracker, $requestSet6);
        $this->handler->process($this->tracker, $requestSet7);

        $this->assertSame(5 + 3 + 1 + 4 + 1 + 0 + 1, $this->tracker->getCountOfLoggedRequests());

        $this->handler->rollBack($this->tracker);

        $this->assertSame(0, $this->tracker->getCountOfLoggedRequests());

        $setsToRetry = $this->handler->getRequestSetsToRetry();

        $expectedSetsToRetry = array(
            $requestSet1, $requestSet2, $requestSet3,
            $requestSet4,
            $requestSet5, $requestSet6, $requestSet7);

        $this->assertEquals($expectedSetsToRetry, $setsToRetry);

        // verify
        $this->assertSame(5, $setsToRetry[0]->getNumberOfRequests());
        $this->assertSame(3, $setsToRetry[1]->getNumberOfRequests());
        $this->assertSame(1, $setsToRetry[2]->getNumberOfRequests());
        $this->assertSame(4, $setsToRetry[3]->getNumberOfRequests());
        $this->assertSame(1, $setsToRetry[4]->getNumberOfRequests());
        $this->assertSame(0, $setsToRetry[5]->getNumberOfRequests());
        $this->assertSame(1, $setsToRetry[6]->getNumberOfRequests());
    }

    public function test_rollBack_shouldCompletelyRemoveARequestSetWhereFirstRequestIsFaulty()
    {
        $requestSet1 = $this->buildRequestSet(5);
        $requestSet2 = $this->buildRequestSetContainingError(7, 0);
        $requestSet3 = $this->buildRequestSet(1);

        $this->handler->process($this->tracker, $requestSet1);

        try {
            $this->handler->process($this->tracker, $requestSet2);
            $this->fail('An expected exception was not triggered');
        } catch (ForcedException $e) {
            $this->handler->onException($requestSet2, $e);
        }

        $this->handler->process($this->tracker, $requestSet3);
        $this->handler->rollBack($this->tracker);

        $setsToRetry = $this->handler->getRequestSetsToRetry();

        $this->assertEquals(array($requestSet1, $requestSet3), $setsToRetry);
    }

    public function test_requestSetsToRetry_shouldAddRequestToAllInvalidRequestSetsContainerButOnlyCorrectOnes()
    {
        $requestSet = $this->buildRequestSetContainingError(7, 4);

        try {
            $this->handler->process($this->tracker, $requestSet);
            $this->fail('An expected exception was not triggered');
        } catch (ForcedException $e) {
            $this->handler->onException($requestSet, $e);
        }

        $this->handler->rollBack($this->tracker);
        $setsToRetry = $this->handler->getRequestSetsToRetry();

        $this->assertEquals($setsToRetry, array($requestSet));
        $this->assertSame(4, $requestSet->getNumberOfRequests());
    }

    public function test_commit_ShouldReturnAnEmptyResultSet_IfProcessWasSuccessful()
    {
        $this->handler->process($this->tracker, $this->buildRequestSet(7));

        $this->handler->commit($this->tracker);

        $setsToRetry = $this->handler->getRequestSetsToRetry();

        $this->assertEquals(array(), $setsToRetry);
        $this->assertFalse($this->handler->hasErrors());

        // make sure something was tracked at all otherwise test would be useless
        $this->assertSame(7, $this->tracker->getCountOfLoggedRequests());
    }

    public function test_process_ShouldRestoreTheEnvironmentOfARequest()
    {
        $serverBackup = $_SERVER;
        $cookieBackup = $_COOKIE;

        $requestSet = $this->buildRequestSet(2);
        $requestSet->setEnvironment(array('server' => array('myserver' => 0), 'cookie' => array('testcookie' => 7)));

        $this->handler->process($this->tracker, $requestSet);

        $this->assertEquals(array('myserver' => 0), $_SERVER);
        $this->assertEquals(array('testcookie' => 7), $_COOKIE);

        $_SERVER = $serverBackup;
        $_COOKIE = $cookieBackup;
    }

    /**
     * @dataProvider getRetryableTransactionErrors
     */
    public function testOnExceptionShouldRetryTheRequestSetAndDiscardWorkDoneInTheTransaction(string $message, int $errno, bool $serverEndsTransaction)
    {
        $this->insertOption('QueuedTrackingTest_beforeError');

        if ($serverEndsTransaction) {
            Tracker::getDatabase()->query('ROLLBACK');
        }

        $requestSet = $this->buildRequestSet(3);
        $this->handler->onException($requestSet, new DbException($message, $errno));

        // without a new transaction PDO would store this in autocommit mode and the rollBack below would throw
        $this->insertOption('QueuedTrackingTest_afterError');

        $this->handler->rollBack($this->tracker);

        $this->assertEquals([$requestSet], $this->handler->getRequestSetsToRetry());
        $this->assertSame(3, $requestSet->getNumberOfRequests());
        $this->assertSame(0, (int) Db::fetchOne(
            'SELECT COUNT(*) FROM `' . Common::prefixTable('option') . '` WHERE option_name LIKE ?',
            ['QueuedTrackingTest_%']
        ));
    }

    public function getRetryableTransactionErrors(): array
    {
        return [
            'deadlock' => ['SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 1213, true],
            'snapshot isolation' => ["SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'log_visit'; try restarting transaction", 1020, true],
            'lock wait timeout' => ['SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', 1205, false],
        ];
    }

    public function testOnExceptionShouldRetryARequestSetThatHitARealLockWaitTimeout()
    {
        $db = Tracker::getDatabase();
        $lockWaitTimeout = (int) $db->fetchOne('SELECT @@SESSION.innodb_lock_wait_timeout');
        $db->query('SET SESSION innodb_lock_wait_timeout = 1');

        // another connection holds the row's lock, so the handler's transaction times out waiting for it
        $otherDb = TrackerDb::connectPiwikTrackerDb();
        $otherTransactionId = $otherDb->beginTransaction();
        $this->insertOption('QueuedTrackingTest_locked', $otherDb);

        $requestSet = $this->buildRequestSet(3);

        try {
            $this->insertOption('QueuedTrackingTest_locked');
            $this->fail('An expected exception was not triggered');
        } catch (DbException $e) {
            $this->handler->onException($requestSet, $e);
        } finally {
            $otherDb->rollBack($otherTransactionId);
            $otherDb->disconnect();
            $db->query('SET SESSION innodb_lock_wait_timeout = ' . $lockWaitTimeout);
        }

        $this->assertTrue($this->handler->hasTransactionErrors());
        $this->assertEquals([$requestSet], $this->handler->getRequestSetsToRetry());
    }

    public function testProcessShouldSkipAnInvalidRequestAndTrackTheFollowingOnes()
    {
        $requestSet = $this->buildRequestSetFromParams([[], ['forceInvalidRequest' => 1], [], []]);

        $this->handler->process($this->tracker, $requestSet);

        $this->assertSame(3, $this->tracker->getCountOfLoggedRequests());
        $this->assertSame(1, $this->tracker->numInvalidRequests);
        $this->assertFalse($this->handler->hasErrors());
        $this->assertSame([0, 2, 3], $this->getIndexes($requestSet));
    }

    public function testProcessShouldSkipARequestRejectedForMissingAccess()
    {
        $requestSet = $this->buildRequestSetFromParams([[], ['forceNoAccess' => 1], []]);

        $this->handler->process($this->tracker, $requestSet);

        $this->assertSame(2, $this->tracker->getCountOfLoggedRequests());
        $this->assertFalse($this->handler->hasErrors());
        $this->assertSame([0, 2], $this->getIndexes($requestSet));
    }

    public function testProcessShouldLeaveOutRequestsForUnknownSitesAndInvalidRequestsOfTheSameSet()
    {
        $requestSet = $this->buildRequestSetFromParams([['idsite' => '0'], [], ['forceInvalidRequest' => 1], [], ['idsite' => '99']]);

        $this->handler->process($this->tracker, $requestSet);

        $this->assertSame(2, $this->tracker->getCountOfLoggedRequests());
        $this->assertFalse($this->handler->hasErrors());
        $this->assertSame([1, 3], $this->getIndexes($requestSet));
        $this->assertSame([$requestSet], $this->handler->getRequestSetsToRetry());
    }

    public function testProcessShouldKeepAllRequestsOfASetWithoutSkippedOrFailedRequests()
    {
        $requestSet = $this->buildRequestSet(4);

        $this->handler->process($this->tracker, $requestSet);

        $this->assertSame([0, 1, 2, 3], $this->getIndexes($requestSet));
    }

    public function testOnExceptionShouldKeepOnlyTheTrackedRequestsIfRequestsWereSkippedBeforeTheError()
    {
        $requestSet = $this->buildRequestSetFromParams([
            [], ['forceInvalidRequest' => 1], [], ['idsite' => '0'], [], ['forceThrow' => 1], [],
        ]);

        $this->processExpectingAnError($requestSet);

        $this->assertTrue($this->handler->hasErrors());
        $this->assertSame([0, 2, 4], $this->getIndexes($requestSet));
        $this->assertSame([$requestSet], $this->handler->getRequestSetsToRetry());
    }

    public function testOnExceptionShouldNotRetryASetWhoseRequestsBeforeTheErrorWereAllSkipped()
    {
        $requestSet = $this->buildRequestSetFromParams([['forceInvalidRequest' => 1], ['idsite' => '0'], ['forceThrow' => 1], []]);

        $this->processExpectingAnError($requestSet);

        $this->assertSame([], $this->handler->getRequestSetsToRetry());
    }

    public function testOnExceptionShouldKeepTheTrackedRequestsIfATypeErrorFollowsASkippedRequest()
    {
        $requestSet = $this->buildRequestSetFromParams([[], ['forceInvalidRequest' => 1], ['forceTypeError' => 1], []]);

        $this->processExpectingAnError($requestSet);

        $this->assertSame([0], $this->getIndexes($requestSet));
        $this->assertSame([$requestSet], $this->handler->getRequestSetsToRetry());
    }

    public function testRollBackShouldRetryTheTrackedRequestsOfEverySetWithoutTheSkippedAndFailedOnes()
    {
        $requestSet1 = $this->buildRequestSetFromParams([[], ['forceInvalidRequest' => 1], []]);
        $requestSet2 = $this->buildRequestSetFromParams([[], ['forceNoAccess' => 1], [], ['forceThrow' => 1], []]);
        $requestSet3 = $this->buildRequestSetFromParams([['idsite' => '0'], []]);

        $this->handler->process($this->tracker, $requestSet1);
        $this->processExpectingAnError($requestSet2);
        $this->handler->process($this->tracker, $requestSet3);

        $this->handler->rollBack($this->tracker);

        $this->assertSame([$requestSet1, $requestSet2, $requestSet3], $this->handler->getRequestSetsToRetry());
        $this->assertSame([0, 2], $this->getIndexes($requestSet1));
        $this->assertSame([0, 2], $this->getIndexes($requestSet2));
        $this->assertSame([1], $this->getIndexes($requestSet3));
        $this->assertSame(0, $this->tracker->getCountOfLoggedRequests());
    }

    private function processExpectingAnError(RequestSet $requestSet): void
    {
        $exception = null;
        try {
            $this->handler->process($this->tracker, $requestSet);
        } catch (\Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception, 'An expected exception was not triggered');
        $this->handler->onException($requestSet, $exception);
    }

    /**
     * @param array[] $paramsPerRequest params to add to (or override in) each request, which is otherwise valid
     */
    private function buildRequestSetFromParams(array $paramsPerRequest): RequestSet
    {
        $requests = [];
        foreach ($paramsPerRequest as $index => $params) {
            $requests[] = new Request($params + ['idsite' => '1', 'index' => $index]);
        }

        $requestSet = new RequestSet();
        $requestSet->setRequests($requests);

        return $requestSet;
    }

    /**
     * @return int[]
     */
    private function getIndexes(RequestSet $requestSet): array
    {
        return array_map(function (Request $request) {
            return (int) $request->getParams()['index'];
        }, $requestSet->getRequests());
    }

    private function insertOption(string $name, ?TrackerDb $db = null): void
    {
        ($db ?? Tracker::getDatabase())->query(
            'INSERT INTO `' . Common::prefixTable('option') . '` (option_name, option_value, autoload) VALUES (?, ?, 0)',
            [$name, '1']
        );
    }

    private function createHandler()
    {
        return new TestHandler();
    }
}
