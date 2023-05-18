<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue;

use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tracker\TrackerConfig;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Version;

class TestProcessor extends Processor {

    public function processRequestSets(Tracker $tracker, $queuedRequestSets)
    {
        return parent::processRequestSets($tracker, $queuedRequestSets);
    }
}

/**
 * @group QueuedTracking
 * @group Queue
 * @group ProcessorTest
 * @group Tracker
 * @group Redis
 */
class ProcessorTest extends IntegrationTestCase
{
    protected $testRequiresRedis = false;

    /**
     * @var TestProcessor
     */
    public $processor;

    /**
     * @var Queue\Manager
     */
    private $queue;

    /**
     * @var Queue\Backend
     */
    private $backend;

    /**
     * @var Queue\Lock
     */
    private $lock;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::createWebsite('2014-01-02 03:04:05');
        
        $this->backend = $this->createMySQLBackend();

        $this->lock = new Queue\Lock($this->backend);

        $this->queue = new Queue\Manager($this->backend, $this->lock);
        $this->queue->setNumberOfAvailableQueues(1);
        $this->queue->setNumberOfRequestsToProcessAtSameTime(3);

        $this->processor = $this->createProcessor();
    }

    public function tearDown(): void
    {
        $this->clearBackend();
        parent::tearDown();
    }

    public function test_process_shouldDoNothing_IfQueueIsEmpty()
    {
        $tracker = $this->processor->process();

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(0);
    }

    public function test_process_shouldDoNothing_IfLessThanRequiredRequestsAreInQueue()
    {
        $this->addRequestSetsToQueue(2);

        $tracker = $this->processor->process();

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(2);
    }

    public function test_process_shouldProcessOnce_IfExactNumberOfRequiredRequestsAreInQueue()
    {
        $this->addRequestSetsToQueue(3);

        $tracker = $this->process();

        $this->assertSame(3, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(0);
    }

    public function test_process_shouldProcessOnlyNumberOfRequiredRequests_IfThereAreMoreRequests()
    {
        $this->addRequestSetsToQueue(5);

        $tracker = $this->process();

        $this->assertSame(3, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(2);
    }

    public function test_process_shouldProcessMultipleTimes_IfThereAreManyMoreRequestsThanRequired()
    {
        $this->addRequestSetsToQueue(10);

        $tracker = $this->process();

        $this->assertSame(9, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(1);
    }

    public function test_process_shouldNotProcessAnything_IfRecordStatisticsIsDisabled()
    {
        $this->addRequestSetsToQueue(8);

        $record = TrackerConfig::getConfigValue('record_statistics');
        TrackerConfig::setConfigValue('record_statistics', 0);
        $tracker = $this->process();
        TrackerConfig::setConfigValue('record_statistics', $record);

        $this->assertSame(0, $tracker->getCountOfLoggedRequests());

        $this->assertSame(8, $this->queue->getNumberOfRequestSetsInAllQueues());
    }

    public function test_process_shouldProcessEachBulkRequestsWithinRequest()
    {
        $this->queue->addRequestSetToQueues($this->buildRequestSet(1));
        $this->queue->addRequestSetToQueues($this->buildRequestSet(2)); // bulk
        $this->queue->addRequestSetToQueues($this->buildRequestSet(4)); // bulk
        $this->queue->addRequestSetToQueues($this->buildRequestSet(1));
        $this->queue->addRequestSetToQueues($this->buildRequestSet(8)); // bulk

        $tracker = $this->process();

        $this->assertSame(7, $tracker->getCountOfLoggedRequests());

        $this->assertNumberOfRequestSetsLeftInQueue(2);
    }

    public function test_processRequestSets_ShouldThrowAnExceptionAndRollback_InCaseWeDoNoLongerHaveTheLock()
    {
        $this->expectException(\Piwik\Plugins\QueuedTracking\Queue\LockExpiredException::class);
        $this->expectExceptionMessage('Rolled back');

        $queuedRequestSets = array(
            $this->buildRequestSet(5)
        );

        $this->processor->processRequestSets($this->createTracker(), $queuedRequestSets);
    }

    public function test_processRequestSets_ShouldReturnAnEmptyArrayIfAllWereTrackerSuccessfully()
    {
        $tracker = $this->createTracker();
        $queuedRequestSets = array(
            $this->buildRequestSet(5),
            $this->buildRequestSet(1),
            $this->buildRequestSet(1),
            $this->buildRequestSet(3),
        );

        $this->acquireAllQueueLocks();
        $requestSetsToRetry = $this->processor->processRequestSets($tracker, $queuedRequestSets);

        $this->assertEquals(array(), $requestSetsToRetry);
        $this->assertSame(5+1+1+3, $tracker->getCountOfLoggedRequests());
    }

    public function test_processRequestSets_ShouldReturnOnlyValidRequestSetsInCaseThereIsAFaultyOne()
    {
        $tracker = $this->createTracker();
        $queuedRequestSets = array(
            $requestSet1 = $this->buildRequestSet(5),
            $requestSet2 = $this->buildRequestSet(1),
            $requestSet3 = $this->buildRequestSetContainingError(1, 0),
            $requestSet4 = $this->buildRequestSet(3),
            $requestSet5 = $this->buildRequestSetContainingError(4, 2),
        );

        $this->acquireAllQueueLocks();
        $requestSetsToRetry = $this->processor->processRequestSets($tracker, $queuedRequestSets);

        $expectedSets = array($requestSet1, $requestSet2, $requestSet4, $requestSet5);
        $this->assertEquals($expectedSets, $requestSetsToRetry);

        // verify request set 5 contains only valid ones
        $this->assertCount(2, $requestSet5->getRequests());
    }

    public function test_processRequestSets_ShouldCatchTypeError()
    {
        $tracker = $this->createTracker();
        $queuedRequestSets = [
            $requestSet1 = $this->buildRequestSet(5),
            $requestSet2 = $this->buildRequestSet(2),
        ];

        // Make one of the requests have an unexpected value type
        $requestParams = $requestSet2->getRequests()[1]->getRawParams();
        // The uadata field is expected to be a JSON string and not an array. It will throw a TypeError during decoding
        $requestParams['uadata'] = [];
        $requestSet2->setRequests([$requestSet2->getRequests()[0], new Tracker\Request($requestParams)]);

        $this->acquireAllQueueLocks();
        $requestSetsToRetry = $this->processor->processRequestSets($tracker, $queuedRequestSets);

        // I couldn't find a param other than uadata that could throw a TypeError and it's not used in older versions
        $isOlderMatomo = version_compare(Version::VERSION, '4.12.0', '>');
        $expectedSets = $isOlderMatomo ? [$requestSet1, $requestSet2] : [];
        $expectedRequestCount = $isOlderMatomo ? 1 : 2;
        $this->assertEquals($expectedSets, $requestSetsToRetry);

        // verify request set 2 contains only valid ones
        $this->assertCount($expectedRequestCount, $requestSet2->getRequests());
    }

    public function test_processRequestSets_ShouldReturnAnEmptyArray_IfNoRequestSetsAreGiven()
    {
        $requestSetsToRetry = $this->processor->processRequestSets($this->createTracker(), array());
        $this->assertEquals(array(), $requestSetsToRetry);

        $requestSetsToRetry = $this->processor->processRequestSets($this->createTracker(), null);
        $this->assertEquals(array(), $requestSetsToRetry);
    }

    public function test_processRequestSets_ShouldResetTheTrackerCounter_IfThereWasAtLeastOneFailure()
    {
        $tracker = $this->createTracker();
        $tracker->setCountOfLoggedRequests(17);
        $queuedRequestSets = array(
            $this->buildRequestSet(4),
            $this->buildRequestSetContainingError(1, 0),
            $this->buildRequestSet(3),
        );

        $this->acquireAllQueueLocks();
        $this->processor->processRequestSets($tracker, $queuedRequestSets);

        $this->assertSame(17, $tracker->getCountOfLoggedRequests());
    }

    public function test_process_ShouldRetryProcessingAllRequestsWithoutTheFailedOnes()
    {
        $this->queue->setNumberOfRequestsToProcessAtSameTime(2);
        // always two request sets at once will be processed

        $this->queue->addRequestSetToQueues($this->buildRequestSet(1));
        $this->queue->addRequestSetToQueues($this->buildRequestSet(5));

        // the first one fails but still should process the first two and the second one
        $this->queue->addRequestSetToQueues($this->buildRequestSetContainingError(4, 2));
        $this->queue->addRequestSetToQueues($this->buildRequestSet(1));

        // the last one fails completely
        $this->queue->addRequestSetToQueues($this->buildRequestSet(1));
        $this->queue->addRequestSetToQueues($this->buildRequestSetContainingError(1, 0));

        // both fail
        $this->queue->addRequestSetToQueues($this->buildRequestSetContainingError(4, 0));
        $this->queue->addRequestSetToQueues($this->buildRequestSetContainingError(2, 0));

        // the first one fails completely
        $this->queue->addRequestSetToQueues($this->buildRequestSetContainingError(1, 0));
        $this->queue->addRequestSetToQueues($this->buildRequestSet(4));

        $this->assertNumberOfRequestSetsLeftInQueue(10);

        $tracker = $this->processor->process($this->createTracker());

        $this->assertSame(1+5+1+2+1+4, $tracker->getCountOfLoggedRequests());
        $this->assertNumberOfRequestSetsLeftInQueue(0);
    }

    public function test_process_ShouldActuallyRetryProcessingAllRequestsWithoutTheFailedOnes()
    {
        $this->queue->setNumberOfRequestsToProcessAtSameTime(2);
        // always two request sets at once will be processed

        $this->queue->addRequestSetToQueues($requestSet1 = $this->buildRequestSet(1));
        $this->queue->addRequestSetToQueues($requestSet2 = $this->buildRequestSet(5));

        // the last one fails completely
        $this->queue->addRequestSetToQueues($requestSet3 = $this->buildRequestSet(1));
        $this->queue->addRequestSetToQueues($requestSet4 = $this->buildRequestSetContainingError(2, 0));

        // the last one fails completely
        $this->queue->addRequestSetToQueues($requestSet5 = $this->buildRequestSetContainingError(3, 0));
        $this->queue->addRequestSetToQueues($requestSet6 = $this->buildRequestSetContainingError(1, 0));

        $self = $this;
        $forwardCallToProcessor = function ($tracker, $requestSets) use ($self) {
            return $self->processor->processRequestSets($tracker, $requestSets);
        };

        $this->acquireAllQueueLocks();
        $mock = $this->getMockBuilder(get_class($this->processor))
                     ->setMethods(array('processRequestSets'))
                     ->setConstructorArgs(array($this->queue, $this->lock))
                     ->getMock();

        $mock->expects($this->at(0))
             ->method('processRequestSets')
             ->with($this->anything(), $this->callback(function ($arg) {
                 return 2 === count($arg) && 1 === $arg[0]->getNumberOfRequests() &&  5 === $arg[1]->getNumberOfRequests();
             }))
            ->will($this->returnCallback($forwardCallToProcessor));

        $mock->expects($this->at(1))
             ->method('processRequestSets')
             ->with($this->anything(), $this->equalTo(array()))
            ->will($this->returnCallback($forwardCallToProcessor));

        $mock->expects($this->at(2)) // one of them fails
             ->method('processRequestSets')
             ->with($this->anything(), $this->callback(function ($arg) {
                 return 2 === count($arg) && 1 === $arg[0]->getNumberOfRequests() &&  2 === $arg[1]->getNumberOfRequests();
             }))
             ->will($this->returnCallback($forwardCallToProcessor));

        $mock->expects($this->at(3)) // retry, this time it should work
             ->method('processRequestSets')
             ->with($this->anything(), $this->callback(function ($arg) {
                 return 1 === count($arg) && 1 === $arg[0]->getNumberOfRequests();
             }))
            ->will($this->returnCallback($forwardCallToProcessor));

        $mock->expects($this->at(4)) // both of them fails
             ->method('processRequestSets')
             ->with($this->anything(), $this->callback(function ($arg) {
                 return 2 === count($arg) && 3 === $arg[0]->getNumberOfRequests() &&  1 === $arg[1]->getNumberOfRequests();
             }))
             ->will($this->returnCallback($forwardCallToProcessor));

        $mock->expects($this->at(5))  // as both fail none should be retried
             ->method('processRequestSets')
             ->with($this->anything(), $this->equalTo(array()))
             ->will($this->returnCallback($forwardCallToProcessor));

        $mock->process($this->createTracker());
    }

    public function test_process_shouldRestoreEnvironmentAfterTrackingRequests()
    {
        $serverBackup = $_SERVER;
        $cookieBackup = $_COOKIE;

        $this->queue->setNumberOfRequestsToProcessAtSameTime(1);
        $requestSet = $this->buildRequestSet(5);
        $requestSet->setEnvironment(array('server' => array('test' => 1), 'cookie' => array('testcookie'=> 7)));
        $this->queue->addRequestSetToQueues($requestSet);

        $tracker = $this->process();

        $this->assertSame(5, $tracker->getCountOfLoggedRequests());

        $this->assertEquals($serverBackup, $_SERVER);
        $this->assertEquals($cookieBackup, $_COOKIE);
    }

    private function acquireAllQueueLocks()
    {
        for ($queueId = 0; $queueId < $this->queue->getNumberOfAvailableQueues(); $queueId++) {
            $this->lock->acquireLock($queueId);
        }
    }

    private function process()
    {
        return $this->processor->process();
    }

    private function assertNumberOfRequestSetsLeftInQueue($numRequestsLeftInQueue)
    {
        $this->assertSame($numRequestsLeftInQueue, $this->queue->getNumberOfRequestSetsInAllQueues());
    }

    private function addRequestSetsToQueue($numRequestSets)
    {
        for ($index = 1; $index <= $numRequestSets; $index++) {
            $this->queue->addRequestSetToQueues($this->buildRequestSet(1));
        }
    }

    private function createProcessor()
    {
        return new TestProcessor($this->queue);
    }

    private function createTracker()
    {
        $tracker = new \Piwik\Plugins\QueuedTracking\tests\Framework\Mock\Tracker();
        return $tracker;
    }
}
