<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue\Processor;

use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Plugins\QueuedTracking\Queue\Processor\Handler;
use Piwik\Plugins\QueuedTracking\tests\Framework\Mock\ForcedException;
use Piwik\Plugins\QueuedTracking\tests\Framework\Mock\Tracker;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tests\Framework\Mock\Tracker\RequestSet;

class TestHandler extends Handler {

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

    public function setUp()
    {
        parent::setUp();

        $this->handler = $this->createHandler();
        $this->tracker = new Tracker();

        $this->handler->init($this->tracker);
    }
    
    public function tearDown()
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
        $this->assertInternalType('string', $this->handler->getTransactionId());
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
        $this->assertTrue($this->handler->hasErrors());

        $this->handler->init($this->tracker);
        $this->assertFalse($this->handler->hasErrors());
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

        $requestSet = $this->buildRequestSet(2);
        $requestSet->setEnvironment(array('server' => array('myserver' => 0)));

        $this->handler->process($this->tracker, $requestSet);

        $this->assertEquals(array('myserver' => 0), $_SERVER);

        $_SERVER = $serverBackup;
    }

    private function createHandler()
    {
        return new TestHandler();
    }

}
