<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;

use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\RequestSet;
use Piwik\Tracker\Request;

/**
 * @group QueuedTracking
 * @group Queue
 * @group QueueTest
 * @group Tracker
 * @group Redis
 */
class QueueTest extends IntegrationTestCase
{
    /**
     * @var Queue
     */
    private $queue;

    public function setUp(): void
    {
        parent::setUp();

        $this->queue = $this->createQueue($id = '');
    }

    private function createQueue($id)
    {
        $redis = $this->createRedisBackend();

        $queue = new Queue($redis, $id);
        $queue->setNumberOfRequestsToProcessAtSameTime(3);

        return $queue;
    }

    public function tearDown(): void
    {
        $this->clearBackend();
        parent::tearDown();
    }

    public function test_getId()
    {
        $this->assertSame('', $this->queue->getId());

        $this->assertSame('2', $this->createQueue('2')->getId());
        $this->assertSame(3, $this->createQueue(3)->getId());
    }

    public function test_internalBuildRequestsSet_ShouldReturnRequestObjects()
    {
        $this->assertTrue($this->buildRequestSetWithIdSite(0) instanceof RequestSet);
        $this->assertEquals(array(), $this->buildRequestSetWithIdSite(0)->getRequests());

        $this->assertTrue($this->buildRequestSetWithIdSite(3) instanceof RequestSet);

        $expected = [
            new Request(['idsite' => 1]),
            new Request(['idsite' => 2]),
            new Request(['idsite' => 3]),
        ];

        $actual = $this->buildRequestSetWithIdSite(3)->getRequests();

        $this->assertEquals($this->setTimestamps($expected), $this->setTimestamps($actual));

        $this->assertTrue($this->buildRequestSetWithIdSite(10) instanceof RequestSet);
        $this->assertCount(10, $this->buildRequestSetWithIdSite(10)->getRequests());
    }

    private function setTimestamps(array $array): array
    {
        foreach ($array as $request) {
            $request->setCurrentTimestamp(1);
        }
        return $array;
    }

    public function test_internalBuildRequestsSet_ShouldBeAbleToSpecifyTheSiteId()
    {
        $this->assertEquals(array(
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 2)),
            new Request(array('idsite' => 2)),
        ), $this->buildRequestSetWithIdSite(3, 2)->getRequests());
    }

    public function test_internalBuildManyRequestsContainingRequests_ShouldReturnManyRequestObjects()
    {
        $this->assertEquals(array(), $this->buildManyRequestSets(0));
        $this->assertEquals(array($this->buildRequestSetWithIdSite(1)), $this->buildManyRequestSets(1));

        $this->assertManyRequestSetsAreEqual(array(
            $this->buildRequestSetWithIdSite(1),
            $this->buildRequestSetWithIdSite(1, 2),
            $this->buildRequestSetWithIdSite(1, 3),
            $this->buildRequestSetWithIdSite(1, 4),
            $this->buildRequestSetWithIdSite(1, 5),
        ), $this->buildManyRequestSets(5));
    }

    public function test_addRequestSet_ShouldNotAddAnything_IfNoRequestsGiven()
    {
        $this->queue->addRequestSet(new RequestSet());
        $this->assertEquals(array(), $this->queue->getRequestSetsToProcess());
    }

    public function test_addRequestSet_ShouldBeAble_ToAddARequest()
    {
        $this->queue->addRequestSet($this->buildRequestSetWithIdSite(1));

        $this->assertManyRequestSetsAreEqual(array($this->buildRequestSetWithIdSite(1)), $this->queue->getRequestSetsToProcess());
    }

    public function test_addRequestSet_ShouldBeAble_ToAddARequestWithManyRequests()
    {
        $this->queue->addRequestSet($this->buildRequestSetWithIdSite(2));
        $this->queue->addRequestSet($this->buildRequestSetWithIdSite(1));

        $expected = array(
            $this->buildRequestSetWithIdSite(2),
            $this->buildRequestSetWithIdSite(1)
        );
        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());
    }

    public function test_shouldProcess_ShouldReturnValue_WhenQueueIsEmptyOrHasTooLessRequests()
    {
        $this->assertFalse($this->queue->shouldProcess());
    }

    public function test_shouldProcess_ShouldReturnTrue_OnceNumberOfRequestsAreAvailable()
    {
        // 2 < 3 should return false
        $this->addRequestSetsToQueue(2);
        $this->assertFalse($this->queue->shouldProcess());

        // now min number of requests is reached
        $this->addRequestSetsToQueue(1);
        $this->assertTrue($this->queue->shouldProcess());

        // when there are more than 3 requests (5) should still return true
        $this->addRequestSetsToQueue(2);
        $this->assertTrue($this->queue->shouldProcess());
    }

    public function test_shouldProcess_ShouldReturnTrue_AsLongAsThereAreEnoughRequestsInQueue()
    {
        // 5 > 3 so should process
        $this->addRequestSetsToQueue(5);
        $this->assertTrue($this->queue->shouldProcess());

        // should no longer process as now 5 - 3 = 2 requests are in queue
        $this->queue->markRequestSetsAsProcessed();
        $this->assertFalse($this->queue->shouldProcess());

        // 6 + 2 = 8 which > 3
        $this->addRequestSetsToQueue(6);
        $this->assertTrue($this->queue->shouldProcess());

        // 8 - 3 = 5 which > 3
        $this->queue->markRequestSetsAsProcessed();
        $this->assertTrue($this->queue->shouldProcess());

        // 5 - 3 = 2 which < 3
        $this->queue->markRequestSetsAsProcessed();
        $this->assertFalse($this->queue->shouldProcess());
    }

    public function test_getNumberOfRequestSetsInQueue_shouldReturnNumberOfSets()
    {
        $this->assertNumberOfRequestSetsInQueue(0);

        $this->addRequestSetsToQueue(2);
        $this->assertNumberOfRequestSetsInQueue(2);

        $this->addRequestSetsToQueue(1);
        $this->assertNumberOfRequestSetsInQueue(3);

        $this->addRequestSetsToQueue(2);
        $this->assertNumberOfRequestSetsInQueue(5);

        $this->queue->markRequestSetsAsProcessed();

        $this->assertNumberOfRequestSetsInQueue(2);
    }

    public function test_delete_shouldReturnFalseIfThereAreNoRequestsInQueue()
    {
        $this->assertNumberOfRequestSetsInQueue(0);

        $this->assertFalse($this->queue->delete());
    }

    public function test_delete_shouldRemoveAllEntriesWithinQueue()
    {
        $this->addRequestSetsToQueue(2);
        $this->assertNumberOfRequestSetsInQueue(2);

        $this->assertTrue($this->queue->delete());

        $this->assertNumberOfRequestSetsInQueue(0);
    }

    public function test_getRequestSetsToProcess_shouldReturnAnEmptyArrayIfQueueIsEmpty()
    {
        $this->assertEquals(array(), $this->queue->getRequestSetsToProcess());
    }

    public function test_getRequestSetsToProcess_shouldReturnAllRequestsIfThereAreLessThanRequired()
    {
        $this->queue->addRequestSet($this->buildRequestSetWithIdSite(2));

        $requests = $this->queue->getRequestSetsToProcess();
        $expected = array($this->buildRequestSetWithIdSite(2));

        $this->assertManyRequestSetsAreEqual($expected, $requests);
    }

    public function test_getRequestSetsToProcess_shouldReturnOnlyTheRequestsThatActuallyNeedToBeProcessed_IfQueueContainsMoreEntries()
    {
        $this->addRequestSetsToQueue(10);

        $requests = $this->queue->getRequestSetsToProcess();
        $expected = $this->buildManyRequestSets(3);

        $this->assertManyRequestSetsAreEqual($expected, $requests);
    }

    public function test_getRequestSetsToProcess_shouldNotRemoveAnyEntriesFromTheQueue()
    {
        $this->addRequestSetsToQueue(5);

        $expected = $this->buildManyRequestSets(3);

        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());
        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());
        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());
    }

    public function test_markRequestSetsAsProcessed_ShouldNotFail_IfQueueIsEmpty()
    {
        $this->queue->markRequestSetsAsProcessed();
        $this->assertEquals(array(), $this->queue->getRequestSetsToProcess());
    }

    public function test_markRequestSetsAsProcessed_ShouldRemoveTheConfiguredNumberOfRequests()
    {
        $this->addRequestSetsToQueue(5);

        $expected = array(
            $this->buildRequestSetWithIdSite(1, 1),
            $this->buildRequestSetWithIdSite(1, 2),
            $this->buildRequestSetWithIdSite(1, 3)
        );

        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());

        $this->queue->markRequestSetsAsProcessed();

        $expected = array(
            $this->buildRequestSetWithIdSite(1, 4),
            $this->buildRequestSetWithIdSite(1, 5)
        );

        $this->assertManyRequestSetsAreEqual($expected, $this->queue->getRequestSetsToProcess());

        $this->queue->markRequestSetsAsProcessed();
        $this->assertEquals(array(), $this->queue->getRequestSetsToProcess());
    }

    public function test__construct_differentIdShouldHaveSeparateNamespace()
    {
        $this->addRequestSetsToQueue(5);

        $this->assertSame(5, $this->queue->getNumberOfRequestSetsInQueue());

        $queue2 = $this->createQueue($id = 2);
        $queue3 = $this->createQueue($id = 3);

        $this->assertSame(0, $queue2->getNumberOfRequestSetsInQueue());
        $this->assertSame(0, $queue3->getNumberOfRequestSetsInQueue());

        $queue2->addRequestSet($this->buildRequestSetWithIdSite(2, 1));
        $queue2->addRequestSet($this->buildRequestSetWithIdSite(1, 1));

        $this->assertSame(5, $this->queue->getNumberOfRequestSetsInQueue());
        $this->assertSame(2, $queue2->getNumberOfRequestSetsInQueue());
        $this->assertSame(0, $queue3->getNumberOfRequestSetsInQueue());
    }

    /**
     * @param RequestSet[] $expected
     * @param RequestSet[] $actual
     */
    private function assertManyRequestSetsAreEqual(array $expected, array $actual)
    {
        $this->assertSameSize($expected, $actual);

        foreach ($expected as $index => $item) {
            $this->assertRequestsAreEqual($item, $actual[$index]);
        }
    }

    private function assertNumberOfRequestSetsInQueue($expectedNumRequests)
    {
        $this->assertSame($expectedNumRequests, $this->queue->getNumberOfRequestSetsInQueue());
    }

    private function buildRequestSetWithIdSite($numRequests, $idSite = null)
    {
        $req = new RequestSet();

        $requests = array();
        for ($index = 1; $index <= $numRequests; $index++) {
            $requests[] = array('idsite' => $idSite ?: $index);
        }

        $req->setRequests($requests);
        $req->rememberEnvironment();

        return $req;
    }

    private function buildManyRequestSets($numRequestSets)
    {
        $requests = array();
        for ($index = 1; $index <= $numRequestSets; $index++) {
            $requests[] = $this->buildRequestSetWithIdSite(1, $index);
        }

        return $requests;
    }

    private function addRequestSetsToQueue($numRequestSets)
    {
        for ($index = 1; $index <= $numRequestSets; $index++) {
            $this->queue->addRequestSet($this->buildRequestSetWithIdSite(1, $index));
        }
    }

}
