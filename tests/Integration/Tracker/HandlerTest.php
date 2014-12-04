<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Tracker;

use Piwik\Db;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Plugins\QueuedTracking\Tracker\Handler;
use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Tests\Framework\Fixture;
use Piwik\Plugins\QueuedTracking\tests\Framework\Mock\Tracker\Response;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Tests\Framework\Mock\Tracker\ScheduledTasksRunner;
use Piwik\Tracker;
use Piwik\Tests\Framework\Mock\Tracker\RequestSet;
use Exception;

/**
 * @group HandlerTest
 * @group Handler
 * @group QueuedTracking
 * @group Tracker
 */
class HandlerTest extends IntegrationTestCase
{
    /**
     * @var Handler
     */
    private $handler;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var Tracker
     */
    private $tracker;

    /**
     * @var RequestSet
     */
    private $requestSet;

    /**
     * @var Queue
     */
    private $queue;

    /**
     * @var Queue\Backend
     */
    private $backend;

    public function setUp()
    {
        parent::setUp();

        Fixture::createWebsite('2014-01-01 00:00:00');
        Tracker\Cache::deleteTrackerCache();

        $this->backend = $this->createRedisBackend();
        $this->queue   = Queue\Factory::makeQueue($this->backend);

        $this->response = new Response();
        $this->handler  = new Handler();
        $this->handler->setResponse($this->response);
        $this->tracker  = new Tracker();
        $this->requestSet = new RequestSet();
    }

    public function tearDown()
    {
        $this->clearRedisDb();
        parent::tearDown();
    }

    public function test_init_ShouldInitiateResponseInstance()
    {
        $this->handler->init($this->tracker, $this->requestSet);

        $this->assertTrue($this->response->isInit);
        $this->assertFalse($this->response->isResponseOutput);
        $this->assertFalse($this->response->isSend);
    }

    public function test_finish_ShouldOutputAndSendResponse()
    {
        $response = $this->handler->finish($this->tracker, $this->requestSet);

        $this->assertEquals('My Dummy Content', $response);

        $this->assertFalse($this->response->isInit);
        $this->assertFalse($this->response->isExceptionOutput);
        $this->assertTrue($this->response->isResponseOutput);
        $this->assertTrue($this->response->isSend);
    }

    public function test_process_ShouldRedirectIfThereIsAValidUrl()
    {
        $_GET['redirecturl'] = 'http://localhost/test?foo=bar';

        $this->setDummyRequests();

        try {
            $this->handler->process($this->tracker, $this->requestSet);
            $this->fail('An expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertContains('Piwik would redirect you to this URL: ' . $_GET['redirecturl'], $e->getMessage());
            unset($_GET['redirecturl']);
        }
    }

    public function test_process_ShouldRedirectIfThereIsAValidBelongingToTheSite()
    {
        $_GET['redirecturl'] = 'http://piwik.net/';

        $this->setDummyRequests();

        try {
            $this->handler->process($this->tracker, $this->requestSet);
            $this->fail('An expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertContains('Piwik would redirect you to this URL: http://piwik.net/', $e->getMessage());
            unset($_GET['redirecturl']);
        }
    }

    public function test_process_ShouldNotRedirectIfThereIsAUrlThatDoesNotBelongToAnySite()
    {
        $_GET['redirecturl'] = 'http://random.piwik.org/test?foo=bar';

        $this->setDummyRequests();

        $this->handler->process($this->tracker, $this->requestSet);
        unset($_GET['redirecturl']);

        $this->assertTrue(true);
    }

    public function test_onException_ShouldOutputAndSendResponse()
    {
        $this->executeOnException($this->buildException());

        $this->assertFalse($this->response->isInit);
        $this->assertFalse($this->response->isResponseOutput);
        $this->assertTrue($this->response->isExceptionOutput);
        $this->assertFalse($this->response->isSend);
    }

    public function test_onException_ShouldPassExceptionToResponse()
    {
        $exception = $this->buildException();

        $this->executeOnException($exception);

        $this->assertSame($exception, $this->response->exception);
        $this->assertSame(500, $this->response->statusCode);
    }

    public function test_onException_ShouldSendStatusCode400IfUnexpectedWebsite()
    {
        $this->executeOnException(new UnexpectedWebsiteFoundException('test'));
        $this->assertSame(400, $this->response->statusCode);
    }

    public function test_onException_ShouldNotRethrowAnException()
    {
        $exception = $this->buildException();

        $this->handler->onException($this->tracker, $this->requestSet, $exception);
        $this->assertTrue(true);
    }

    public function test_onAllRequestsTracked_ShouldNeverTriggerScheduledTasksEvenIfEnabled()
    {
        $runner = new ScheduledTasksRunner();
        $runner->shouldRun = true;

        $this->handler->setScheduledTasksRunner($runner);
        $this->handler->onAllRequestsTracked($this->tracker, $this->requestSet);

        $this->assertTrue($runner->ranScheduledTasks);
    }

    public function test_process_ShouldUpdateNumberOfLoggedRequests()
    {
        $this->assertSame(0, $this->tracker->getCountOfLoggedRequests());

        $this->processDummyRequests();

        $this->assertSame(2, $this->tracker->getCountOfLoggedRequests());
    }

    public function test_process_ShouldWriteRequestsToQueue()
    {
        $this->assertSame(0, $this->queue->getNumberOfRequestSetsInQueue());

        $this->processDummyRequests();

        $this->assertSame(1, $this->queue->getNumberOfRequestSetsInQueue());

        $this->processDummyRequests();

        $this->assertSame(2, $this->queue->getNumberOfRequestSetsInQueue());

        // verify
        $this->queue->setNumberOfRequestsToProcessAtSameTime(2);
        $requestSet = $this->queue->getRequestSetsToProcess();
        $this->assertCount(2, $requestSet);

        $requests = $requestSet[0]->getRequests();
        $this->assertCount(2, $requests);
        $this->assertEquals(array('idsite' => 1, 'url' => 'http://localhost/foo?bar'), $requests[0]->getParams());
        $this->assertEquals(array('idsite' => 1, 'url' => 'http://localhost'), $requests[1]->getParams());
    }

    public function test_process_ShouldDirectlyProcessQueueOnceNumRequestsPresent_IfEnabled()
    {
        Queue\Factory::getSettings()->numRequestsToProcess->setValue(2);
        $this->handler->enableProcessingInTrackerMode();

        $this->assertSame(0, $this->queue->getNumberOfRequestSetsInQueue());

        $this->processDummyRequests();

        $this->assertSame(1, $this->queue->getNumberOfRequestSetsInQueue());

        $this->processDummyRequests();

        $this->assertSame(0, $this->queue->getNumberOfRequestSetsInQueue());
    }

    public function test_process_ShouldNotDirectlyProcessQueue_IfDisabled()
    {
        $this->queue->setNumberOfRequestsToProcessAtSameTime(1);

        $this->assertSame(0, $this->queue->getNumberOfRequestSetsInQueue());

        $this->processDummyRequests();

        $this->assertSame(1, $this->queue->getNumberOfRequestSetsInQueue());

        $this->processDummyRequests();

        $this->assertSame(2, $this->queue->getNumberOfRequestSetsInQueue());
    }

    public function test_process_ShouldNotDirectlyProcessQueue_IfAlreadyLocked()
    {
        $processor = new Queue\Processor($this->backend);
        $processor->acquireLock();

        $this->queue->setNumberOfRequestsToProcessAtSameTime(1);
        $this->handler->enableProcessingInTrackerMode();

        $this->assertSame(0, $this->queue->getNumberOfRequestSetsInQueue());

        $this->processDummyRequests();

        $this->assertSame(1, $this->queue->getNumberOfRequestSetsInQueue());

        $this->processDummyRequests();

        $this->assertSame(2, $this->queue->getNumberOfRequestSetsInQueue());

        $processor->unlock();
    }

    public function test_process_ShouldNotCreateADatabaseConnectionAtAnyTime()
    {
        $this->setDummyRequests();
        Queue\Factory::getSettings()->queueEnabled->getValue(); // this will cause a db query but will be cached afterwards
        Db::destroyDatabaseObject();

        $this->handler->init($this->tracker, $this->requestSet);
        $this->assertNotDbConnectionCreated();
        $this->handler->onStartTrackRequests($this->tracker, $this->requestSet);
        $this->assertNotDbConnectionCreated();
        $this->handler->process($this->tracker, $this->requestSet);
        $this->assertNotDbConnectionCreated();
        $this->handler->onAllRequestsTracked($this->tracker, $this->requestSet);
        $this->assertNotDbConnectionCreated();
        $this->handler->finish($this->tracker, $this->requestSet);
        $this->assertNotDbConnectionCreated();
    }

    private function buildException()
    {
        return new \Exception('MyMessage', 292);
    }

    private function executeOnException(Exception $exception)
    {
        try {
            $this->handler->onException($this->tracker, $this->requestSet, $exception);
        } catch (Exception $e) {
        }
    }

    private function processDummyRequests()
    {
        $this->setDummyRequests();

        $this->handler->process($this->tracker, $this->requestSet);
    }

    private function setDummyRequests()
    {
        $this->requestSet->setRequests(array(
            array('idsite' => 1, 'url' => 'http://localhost/foo?bar'),
            array('idsite' => 1, 'url' => 'http://localhost'),
        ));
    }
}
