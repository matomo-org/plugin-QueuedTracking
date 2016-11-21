<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue;

use Piwik\Tests\Framework\Fixture;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\RequestSet;

class TestManager extends Queue\Manager
{
    public function getQueueIdForVisitor($visitorId)
    {
        return parent::getQueueIdForVisitor($visitorId);
    }

}

/**
 * @group QueuedTracking
 * @group Queue
 * @group QueueTest
 * @group Tracker
 * @group Redis
 */
class ManagerTest extends IntegrationTestCase
{
    /**
     * @var TestManager
     */
    private $manager;

    /**
     * @var Queue\Lock
     */
    private $lock;

    public function setUp()
    {
        parent::setUp();

        Fixture::createSuperUser();

        $manager = $this->createQueueManager();
        $this->manager = $manager['queue'];
        $this->lock    = $manager['lock'];
    }

    private function createQueueManager()
    {
        $redis = $this->createRedisBackend();
        $lock  = new Queue\Lock($redis);
        $queue = new TestManager($redis, $lock);
        $queue->setNumberOfAvailableQueues(4);

        return array('queue' => $queue, 'lock' => $lock);
    }

    public function tearDown()
    {
        $this->clearRedisDb();
        parent::tearDown();
    }

    public function test_getNumberOfAvailableQueues_default()
    {
        $this->assertSame(4, $this->manager->getNumberOfAvailableQueues());
    }

    public function test_setNumberOfAvailableQueues_ShouldOverwriteDefault()
    {
        $this->manager->setNumberOfAvailableQueues(3);
        $this->assertSame(3, $this->manager->getNumberOfAvailableQueues());
    }

    public function test_getNumberOfRequestsToProcessAtSameTime()
    {
        $this->assertSame(50, $this->manager->getNumberOfRequestsToProcessAtSameTime());
    }

    public function test_setNumberOfRequestsToProcessAtSameTime_ShouldOverwriteDefault()
    {
        $this->manager->setNumberOfRequestsToProcessAtSameTime(4);
        $this->assertSame(4, $this->manager->getNumberOfRequestsToProcessAtSameTime());
    }

    public function test_canAcquireMoreLocks_shouldReturnTrue_IfNoQueueIsLocked()
    {
        $this->assertTrue($this->manager->canAcquireMoreLocks());
    }

    public function test_canAcquireMoreLocks_shouldReturnTrue_IfMoreQueuesArePossibleToLock()
    {
        $this->manager->setNumberOfAvailableQueues(2);

        $this->makeSureItIsPossibleToLockQueues();

        $this->assertNotEmpty($this->manager->lockNext());
        $this->assertTrue($this->manager->canAcquireMoreLocks());
    }

    public function test_canAcquireMoreLocks_shouldReturnFalse_IfNotPossibleToLockAmyMore()
    {
        $this->manager->setNumberOfAvailableQueues(1);

        $this->makeSureItIsPossibleToLockQueues();
        $this->assertNotEmpty($this->manager->lockNext());

        // all queues locked now (only one available)
        $this->assertFalse($this->manager->canAcquireMoreLocks());
    }

    public function test_getQueueIdForVisitor_shouldRespectNumQueuesAvailable()
    {
        $this->assertQueueIdForVisitorIdEquals(1, '2044b47c789946d1');
        $this->assertQueueIdForVisitorIdEquals(3, '76d0d5448a361511');
        $this->assertQueueIdForVisitorIdEquals(0, '8c82f3727583bd19');
        $this->assertQueueIdForVisitorIdEquals(1, 'efad5df415b6a1ec');
        $this->assertQueueIdForVisitorIdEquals(3, '002c8a8527435407');
        $this->assertQueueIdForVisitorIdEquals(2, '103bc35dd05ecf2d');
        $this->assertQueueIdForVisitorIdEquals(1, '25c2355bfa4f55d2');
        $this->assertQueueIdForVisitorIdEquals(0, '2843128ac6a7f239');

        $this->manager->setNumberOfAvailableQueues(3);

        $this->assertQueueIdForVisitorIdEquals(1, '00e0bfc49cfc03d3');
        $this->assertQueueIdForVisitorIdEquals(2, '083a2a874d54745e');
        $this->assertQueueIdForVisitorIdEquals(1, '240a7009f9fb31b9');
        $this->assertQueueIdForVisitorIdEquals(2, '6de0339f134685c5');
        $this->assertQueueIdForVisitorIdEquals(0, '7b092c476316f0dc');
        $this->assertQueueIdForVisitorIdEquals(2, 'f196ef9e45d9627a');
        $this->assertQueueIdForVisitorIdEquals(0, 'ce5c055abf96abd0');
        $this->assertQueueIdForVisitorIdEquals(2, 'be4d1f0c8f8749b5');
    }

    public function test_getAllQueues_shouldReturnAnArrayOfQueueInstances()
    {
        $queues = $this->manager->getAllQueues();
        $this->assertCount(4, $queues);

        foreach ($queues as $queue) {
            $this->assertTrue($queue instanceof Queue);
        }
    }

    public function test_getAllQueues_returnedNumberOfInstancesShouldDependOnConfiguredAvailableQueues()
    {
        $this->manager->setNumberOfAvailableQueues(1);
        $queues = $this->manager->getAllQueues();
        $this->assertCount(1, $queues);

        $this->manager->setNumberOfAvailableQueues(3);
        $queues = $this->manager->getAllQueues();
        $this->assertCount(3, $queues);
    }

    private function assertQueueIdForVisitorIdEquals($expectedQueueId, $visitorId)
    {
        $queueId = $this->manager->getQueueIdForVisitor($visitorId);

        $this->assertSame($expectedQueueId, $queueId);
    }

    private function makeSureItIsPossibleToLockQueues($numQueuesToLock = 1)
    {
        for ($i = 0; $i < $numQueuesToLock; $i++) {
            // it is only possible to lock a queue if there are the required number of requests to process
            $this->manager->setNumberOfRequestsToProcessAtSameTime(1);
            $this->manager->createQueue($i)->addRequestSet($this->buildRequestSetWithIdSite(1));
        }
    }

    public function test_lockNext_shouldNotLock_IfNoQueueNeedsToBeLocked()
    {
        $this->assertNull($this->manager->lockNext());
    }

    public function test_lockNext_shouldLockAndReturnQueue_IfAQueueNeedsToBeProcessed()
    {
        $this->makeSureItIsPossibleToLockQueues();

        $queue = $this->manager->lockNext();
        $this->assertTrue($queue instanceof Queue);

        $this->assertQueueManagerIsLocked();

        // it should return the same queue again as nothing was actually processed
        $queue = $this->manager->lockNext();
        $this->assertTrue($queue instanceof Queue);

        $this->assertQueueManagerIsLocked();

        // now we mark it has processed
        $queue->markRequestSetsAsProcessed();

        // the only queue that exists no longer needs to be processed and should not lock anything therefore
        $this->assertEmpty($this->manager->lockNext());

        $this->assertNotQueueManagerIsLocked();
    }

    public function test_unlock_shouldUnlockQueue()
    {
        $this->makeSureItIsPossibleToLockQueues();

        $this->manager->lockNext();
        $this->assertQueueManagerIsLocked();

        $this->manager->unlock();

        $this->assertNotQueueManagerIsLocked();
    }

    public function test_expireLock_shouldSucceedOnlyIfLocked()
    {
        $this->makeSureItIsPossibleToLockQueues();

        $this->manager->lockNext();
        $this->assertTrue($this->manager->expireLock(40));

        $this->manager->unlock();
        $this->assertFalse($this->manager->expireLock(40));
    }

    public function test_addRequestSetToQueues_shouldNotAddAnythingIfNoRequestsGiven()
    {
        $this->manager->addRequestSetToQueues($this->buildRequestSetWithIdSite(0));
        $this->assertSame(0, $this->manager->getNumberOfRequestSetsInAllQueues());
    }

    public function test_addRequestSetToQueues_getNumberOfRequestSetsInAllQueues_shouldMoveAllRequestsIntoQueues()
    {
        $this->addRequestSetToQueues(21);

        $this->assertSame(21, $this->manager->getNumberOfRequestSetsInAllQueues());
    }

    public function test_addRequestSetToQueues_getNumberOfRequestSetsInAllQueues_shouldMoveThemIntoDifferentQueues()
    {
        for ($i = 0; $i < 26; $i++) {
            $requestSet = $this->buildRequestSetWithIdSite(1, array('uid' => $i));

            $this->manager->addRequestSetToQueues($requestSet);
        }

        $this->assertSame(26, $this->manager->getNumberOfRequestSetsInAllQueues());
        $this->assertNumberOfRequestSetsInQueueEquals(9,  $queueId = 0);
        $this->assertNumberOfRequestSetsInQueueEquals(3, $queueId = 1);
        $this->assertNumberOfRequestSetsInQueueEquals(4,  $queueId = 2);
        $this->assertNumberOfRequestSetsInQueueEquals(10, $queueId = 3);
        $this->assertNumberOfRequestSetsInQueueEquals(0,  $queueId = 4); // this queue is not available
    }

    public function test_addRequestSetToQueues_getNumberOfRequestSetsInAllQueues_shouldMoveAllInSameQueue_IfAllHaveSameUID()
    {
        $expectedRequestSets = array();

        for ($i = 0; $i < 26; $i++) {
            $requestSet = $this->buildRequestSetWithIdSite(1, array('uid' => 4));

            $this->manager->addRequestSetToQueues($requestSet);
            $expectedRequestSets[] = $requestSet;
        }

        $this->assertSame(26, $this->manager->getNumberOfRequestSetsInAllQueues());
        $this->assertNumberOfRequestSetsInQueueEquals(26, $queueId = 0);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 1);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 2);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 3);

        // verify all 26 written into queue
        $this->assertRequestSetsInQueueEquals($expectedRequestSets, 0);
    }

    public function test_addRequestSetToQueues_shouldMoveAllInSameQueue_IfAllHaveSameUidAndTheyAreInOneRequestSet()
    {
        $requestSet = $this->buildRequestSetWithIdSite(15, array('uid' => 4));

        $this->manager->addRequestSetToQueues($requestSet);

        $this->assertSame(1, $this->manager->getNumberOfRequestSetsInAllQueues());
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 0);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 1);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 2);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 3);

        // verify all 15 written into queue
        $this->assertRequestSetsInQueueEquals(array($requestSet), 0);
    }

    public function test_addRequestSetToQueues_shouldMoveIntoDifferentQueues_IfThereAreManyDifferentRequestsInOneSet()
    {
        $req = new RequestSet();

        $requests = array();
        $requests[0] = array('idsite' => 1, 'uid' => 1);
        $requests[1] = array('idsite' => 1, 'uid' => 2);
        $requests[2] = array('idsite' => 1, 'uid' => 3);
        $requests[3] = array('idsite' => 1, 'uid' => 5);
        $requests[4] = array('idsite' => 3, 'uid' => 1);
        $requests[5] = array('idsite' => 1, 'uid' => 3);

        $req->setRequests($requests);
        $req->rememberEnvironment();

        $this->manager->addRequestSetToQueues($req);

        // uids from requests should
        $this->assertSame(2, $this->manager->getNumberOfRequestSetsInAllQueues());

        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 0);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 1);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 2);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 3);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 4);

        $req->setRequests(array($requests[1]));
        $this->assertRequestSetsInQueueEquals($req, 0);

        $req->setRequests(array($requests[3]));
        $this->assertRequestSetsInQueueEquals($req, 3);

        $req->setRequests(array($requests[0], $requests[2], $requests[4], $requests[5]));
        $this->assertRequestSetsInQueueEquals($req, 3);
    }

    public function test_moveSomeQueuesIfNeeded_ShouldReturnFalseIfNoQueuesNeedToBeMoved()
    {
        $oldNumWorkers = 5;
        $this->manager->setNumberOfAvailableQueues($oldNumWorkers);

        $this->assertFalse($this->manager->moveSomeQueuesIfNeeded($oldNumWorkers, $oldNumWorkers));

        $this->assertFalse($this->manager->moveSomeQueuesIfNeeded($oldNumWorkers + 1, $oldNumWorkers));
    }

    public function test_moveSomeQueuesIfNeeded_ShouldReturnTrueIfQueuesNeedToBeMovedEvenIfTheyDoNotContainAnyRequests()
    {
        $newNumWorkers = 5;
        $oldNumWorkers = 10;

        $this->manager->setNumberOfAvailableQueues($oldNumWorkers);

        $this->assertTrue($this->manager->moveSomeQueuesIfNeeded($newNumWorkers, $oldNumWorkers));
    }

    public function test_moveSomeQueuesIfNeeded_ShouldActuallyMoveQueues()
    {
        $newNumWorkers = 5;
        $oldNumWorkers = 10;

        $this->manager->setNumberOfAvailableQueues($oldNumWorkers);

        foreach ($this->manager->getAllQueues() as $queue) {
            $requestSet = $this->buildRequestSetWithIdSite(1);
            $queue->addRequestSet($requestSet);
        }

        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 0);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 1);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 2);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 3);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 4);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 5);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 6);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 7);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 8);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 9);

        $this->manager->moveSomeQueuesIfNeeded($newNumWorkers, $oldNumWorkers);

        $this->assertNumberOfRequestSetsInQueueEquals(6, $queueId = 0);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 1);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 2);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 3);
        $this->assertNumberOfRequestSetsInQueueEquals(1, $queueId = 4);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 5);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 6);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 7);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 8);
        $this->assertNumberOfRequestSetsInQueueEquals(0, $queueId = 9);
    }

    private function assertRequestSetsInQueueEquals($expectedRequestSets, $queueId)
    {
        // verify all 26 written into queue
        $requestSets = $this->manager->createQueue($queueId)->getRequestSetsToProcess();
        $this->assertCount(count($expectedRequestSets), $requestSets);

        foreach ($expectedRequestSets as $i => $requestSet) {
            $this->assertRequestsAreEqual($requestSet, $requestSets[$i]);
        }
    }

    private function assertNumberOfRequestSetsInQueueEquals($expectedNumRequests, $queueId)
    {
        $numRequests = $this->manager->createQueue($queueId)->getNumberOfRequestSetsInQueue();
        $this->assertSame($expectedNumRequests, $numRequests);
    }

    private function assertQueueManagerIsLocked()
    {
        $this->assertTrue($this->lock->isLocked());
    }

    private function assertNotQueueManagerIsLocked()
    {
        $this->assertFalse($this->lock->isLocked());
    }

    private function buildRequestSetWithIdSite($numRequests, $additionalParams = array())
    {
        $req = new RequestSet();

        $requests = array();
        for ($index = 1; $index <= $numRequests; $index++) {
            $requests[] = array_merge(array('idsite' => 1, 'cip' => '192.168.33.11', 'token_auth' => Fixture::getTokenAuth()), $additionalParams);
        }

        $req->setRequests($requests);
        $req->rememberEnvironment();

        return $req;
    }

    private function addRequestSetToQueues($numRequestSets)
    {
        for ($index = 1; $index <= $numRequestSets; $index++) {
            $this->manager->addRequestSetToQueues($this->buildRequestSetWithIdSite(1));
        }
    }

}
