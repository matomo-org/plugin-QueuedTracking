<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Settings;

use Piwik\Container\StaticContainer;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Plugins\QueuedTracking\PluginSettings;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\RequestSet;

/**
 * @group QueuedTracking
 * @group SettingsTest
 * @group Plugins
 * @group Tracker
 */
class NumWorkersTest extends IntegrationTestCase
{
    /**
     * @var PluginSettings
     */
    private $settings;

    public function setUp()
    {
        parent::setUp();
        $this->clearRedisDb();

        $this->settings = self::$fixture->piwikEnvironment->getContainer()->get('Piwik\Plugins\QueuedTracking\PluginSettings');
    }

    public function tearDown()
    {
        $this->clearRedisDb();
        parent::tearDown();
    }

    public function test_numQueueWorkers_WhenChangingAValueItMovesRequestsIntoDifferentQueues()
    {
        $oldNumWorkers = 4;
        $newNumWorkers = 2;

        $this->settings->numQueueWorkers->setValue($oldNumWorkers);

        $manager = Factory::makeQueueManager(Factory::makeBackend());

        $requestSet = new RequestSet();
        $requestSet->setRequests(array('idsite' => '1', '_id' => 1));

        $queues = $manager->getAllQueues();
        foreach ($queues as $queue) {
            $queue->addRequestSet($requestSet);
        }

        $this->assertSame(4, $manager->getNumberOfRequestSetsInAllQueues());
        $this->assertSame(1, $queues[0]->getNumberOfRequestSetsInQueue());
        $this->assertSame(1, $queues[1]->getNumberOfRequestSetsInQueue());
        $this->assertSame(1, $queues[2]->getNumberOfRequestSetsInQueue());
        $this->assertSame(1, $queues[3]->getNumberOfRequestSetsInQueue());

        $this->settings->numQueueWorkers->setValue($newNumWorkers);

        $this->assertSame(4, $manager->getNumberOfRequestSetsInAllQueues());
        $this->assertGreaterThanOrEqual(1, $queues[0]->getNumberOfRequestSetsInQueue());
        $this->assertGreaterThanOrEqual(1, $queues[1]->getNumberOfRequestSetsInQueue());
        $this->assertSame(0, $queues[2]->getNumberOfRequestSetsInQueue());
        $this->assertSame(0, $queues[3]->getNumberOfRequestSetsInQueue());
    }

}
