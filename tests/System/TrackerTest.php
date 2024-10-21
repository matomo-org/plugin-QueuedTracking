<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\System;

use Piwik\Common;
use Piwik\Config;
use Piwik\Db;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\SystemSettings;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\SystemTestCase;
use Piwik\Tests\Framework\TestingEnvironmentVariables;

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

    public function setUp(): void
    {
        parent::setUp();

        if (!IntegrationTestCase::isRedisAvailable()) {
            $this->markTestSkipped('Redis extension is not installed, skipping test');
        }

        self::$fixture->performSetup();

        $idSite = 1;
        $dateTime = '2014-01-01 00:00:01';

        if (!Fixture::siteCreated($idSite)) {
            Fixture::createWebsite($dateTime);
        }

        $this->tracker = Fixture::getTracker($idSite, $dateTime, $defaultInit = true);
        $this->enableQueue();
    }

    public function tearDown(): void
    {
        $this->createRedisBackend()->flushAll();

        self::$fixture->performTearDown();

        parent::tearDown();
    }

    public function test_response_ShouldReturnBulkTrackingResponse_IfQueueIsDisabledAndProcessNormallyAndUsesBulk()
    {
        $this->disableQueue();

        $response = $this->doTrackNumberOfRequests(2);

        $this->assertEquals('{"status":"success","tracked":2,"invalid":0,"invalid_indices":[]}', $response);

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
        $response = $this->doTrackNumberOfRequests(2, false);

        Fixture::checkResponse($response);
    }

    public function test_response_ShouldContainJsonResponseIfTrackedViaQueue_InBulk()
    {
        $response = $this->doTrackNumberOfRequests(2);

        Fixture::checkResponse($response);
    }

    public function test_response_ShouldSetThirdPartyCookieIfEnabled()
    {
        $this->enableThirdPartyCookie();

        $response = $this->doTrackNumberOfRequests(1);

        $this->disableThirdPartyCookie();

        $cookieName = $this->getThirdPartyCookieName();
        $this->assertNotEmpty($this->tracker->getIncomingTrackerCookie($cookieName));
    }

    public function test_response_ShouldAcceptThirdPartyCookieIfPresent()
    {
        $this->enableThirdPartyCookie();

        $cookieName = $this->getThirdPartyCookieName();

        $response = $this->doTrackNumberOfRequests(1);
        $cookieValueOne = $this->tracker->getIncomingTrackerCookie($cookieName);

        $this->tracker->setNewVisitorId();
        $this->tracker->setOutgoingTrackerCookie($cookieName, $cookieValueOne);

        $response = $this->doTrackNumberOfRequests(1);
        $cookieValueTwo = $this->tracker->getIncomingTrackerCookie($cookieName);

        $this->disableThirdPartyCookie();

        $this->assertEquals($cookieValueOne, $cookieValueTwo);
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
        while ($queue->getNumberOfRequestSetsInAllQueues() !== 0) {
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
        $settings = Queue\Factory::getSettings();
        $settings->queueEnabled->setValue(true);
        $settings->numRequestsToProcess->setValue($this->requestProcessLimit);
        $settings->processDuringTrackingRequest->setValue(true);
        $settings->numQueueWorkers->setValue(1);
        $settings->redisDatabase->setValue(15);
        $settings->redisHost->setValue('127.0.0.1');
        $settings->redisPort->setValue(6379);
        $settings->save();
    }

    protected function disableQueue()
    {
        $settings = new SystemSettings();
        $settings->queueEnabled->setValue(false);
        $settings->save();
    }

    protected function createQueue()
    {
        $backend = $this->createRedisBackend();

        return Queue\Factory::makeQueueManager($backend);
    }

    protected function enableThirdPartyCookie()
    {
        $testingEnvironment = new TestingEnvironmentVariables();
        $testingEnvironment->overrideConfig('Tracker', 'use_third_party_id_cookie', 1);
        $testingEnvironment->save();
    }

    protected function disableThirdPartyCookie()
    {
        $testingEnvironment = new TestingEnvironmentVariables();
        $testingEnvironment->overrideConfig('Tracker', 'use_third_party_id_cookie', 0);
        $testingEnvironment->save();
    }

    protected function getThirdPartyCookieName()
    {
        return Config::getInstance()->Tracker['cookie_name'];
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
        $this->assertSame($numRequestSets, $queue->getNumberOfRequestSetsInAllQueues());
    }
}
