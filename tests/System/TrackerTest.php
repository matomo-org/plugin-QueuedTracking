<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\System;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\QueuedTracking;
use Piwik\Plugins\QueuedTracking\Settings;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\SystemTestCase;

/**
 * @group QueuedTracking
 * @group TrackerTest
 * @group Tracker
 * @group Plugins
 */
class TrackerTest extends SystemTestCase
{
    /**
     * @var \PiwikTracker
     */
    private $tracker;

    private $requestProcessLimit = 5;

    public function setUp()
    {
        parent::setUp();

        if (self::isTravisCI() && self::isPhpVersion53()) {
            $this->markTestSkipped('Redis seems to be not enabled in nginx on Travis + PHP 5.3.3');
        }

        self::$fixture->performSetup();

        $idSite = 1;
        $dateTime = '2014-01-01 00:00:01';

        if (!Fixture::siteCreated($idSite)) {
            Fixture::createWebsite($dateTime);
        }

        $queuedTracking = new QueuedTracking();
        $queuedTracking->configureQueueTestBackend();

        $this->tracker = Fixture::getTracker($idSite, $dateTime, $defaultInit = true);
        $this->enableQueue();
    }

    public function tearDown()
    {
        $this->createRedisBackend()->flushAll();
        Queue\Factory::clearSettings();

        self::$fixture->performTearDown();

        parent::tearDown();
    }

    public function test_response_ShouldReturnBulkTrackingResponse_IfQueueIsDisabledAndProcessNormallyAndUsesBulk()
    {
        $this->disableQueue();

        $response = $this->doTrackNumberOfRequests(2);

        $this->assertEquals('{"status":"success","tracked":2}', $response);

        // verify nothing in queue
        $this->assertNumEntriesInQueue(0);
    }

    public function test_response_ShouldReturnNormalTrackingResponse_IfQueueIsDisabledAndProcessNormally()
    {
        $this->disableQueue();

        $response = $this->doTrackNumberOfRequests(2, false);

        Fixture::checkResponse($response);

        // verify nothing in queue
        $this->assertNumEntriesInQueue(0);
    }

    public function test_response_ShouldContainTrackingGifIfTrackedViaQueue()
    {
        $response = $this->doTrackNumberOfRequests(2);

        Fixture::checkResponse($response);
    }

    public function test_response_ShouldActuallyAddRequestsToQueue()
    {
        $this->doTrackNumberOfRequests(2);
        $this->assertNumEntriesInQueue(1);

        $this->doTrackNumberOfRequests(1);
        $this->assertNumEntriesInQueue(2);
    }

    public function test_response_ShouldNotTrackAnythingUnlessQueueStartedProcessing()
    {
        for ($i = 1; $i < $this->requestProcessLimit; $i++) {
            $this->doTrackNumberOfRequests(1);
        }

        $this->assertEmpty($this->getIdVisit(1));
    }

    /**
     * @medium
     */
    public function test_response_ShouldStartProcessingRequestsOnceLimitAchieved()
    {
        for ($i = 1; $i < $this->requestProcessLimit; $i++) {
            $response = $this->doTrackNumberOfRequests(2);
            Fixture::checkResponse($response);
            $this->assertNumEntriesInQueue($i);
        }

        $this->assertEmpty($this->getIdVisit(1)); // make sure nothing tracked yet

        // the last request should trigger processing them
        $this->doTrackNumberOfRequests(1);
        // it sends us the response before actually processing them

        $queue = $this->createQueue();
        while ($queue->getNumberOfRequestSetsInQueue() !== 0) {
            usleep(100);
        }

        $this->assertNumEntriesInQueue(0);

        // make sure actually tracked
        $this->assertNotEmpty($this->getIdVisit(1));
        $this->assertActionEquals('Test', 1);
    }

    private function doTrackNumberOfRequests($numRequests, $inBulk = true)
    {
        $inBulk && $this->tracker->enableBulkTracking();

        for ($i = 0; $i < $numRequests; $i++) {
            $response = $this->tracker->doTrackPageView('Test');
        }

        if ($inBulk) {
            $response = $this->tracker->doBulkTrack();
        }

        return $response;
    }

    protected function enableQueue()
    {
        $settings = new Settings();
        $settings->queueEnabled->setValue(true);
        $settings->numRequestsToProcess->setValue($this->requestProcessLimit);
        $settings->save();
    }

    protected function disableQueue()
    {
        $settings = new Settings();
        $settings->queueEnabled->setValue(false);
        $settings->save();
    }

    protected function createQueue()
    {
        return Queue\Factory::makeQueue($this->createRedisBackend());
    }

    protected function clearRedisDb()
    {
        $this->createRedisBackend()->flushAll();
    }

    protected function createRedisBackend()
    {
        return Queue\Factory::makeBackend();
    }

    private function assertActionEquals($expected, $idaction)
    {
        $actionName = Db::fetchOne("SELECT name FROM " . Common::prefixTable('log_action') . " WHERE idaction = ?", array($idaction));
        $this->assertEquals($expected, $actionName);
    }

    private function getIdVisit($idVisit)
    {
        return Db::fetchRow("SELECT * FROM " . Common::prefixTable('log_visit') . " WHERE idvisit = ?", array($idVisit));
    }

    private function assertNumEntriesInQueue($numRequestSets)
    {
        $queue = $this->createQueue();
        $this->assertSame($numRequestSets, $queue->getNumberOfRequestSetsInQueue());
    }
}
