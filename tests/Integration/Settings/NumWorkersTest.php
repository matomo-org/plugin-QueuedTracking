<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Settings;

use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Plugins\QueuedTracking\SystemSettings;
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
     * @var SystemSettings
     */
    private $settings;

    public function setUp(): void
    {
        parent::setUp();
        $this->clearBackend();

        $container = self::$fixture->piwikEnvironment->getContainer();
        $this->settings = $container->get('Piwik\Plugins\QueuedTracking\SystemSettings');
    }

    public function tearDown(): void
    {
        $this->clearBackend();
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
        $this->settings->save();

        $this->assertSame(4, $manager->getNumberOfRequestSetsInAllQueues());
        $this->assertGreaterThanOrEqual(1, $queues[0]->getNumberOfRequestSetsInQueue());
        $this->assertGreaterThanOrEqual(1, $queues[1]->getNumberOfRequestSetsInQueue());
        $this->assertSame(0, $queues[2]->getNumberOfRequestSetsInQueue());
        $this->assertSame(0, $queues[3]->getNumberOfRequestSetsInQueue());
    }

}
