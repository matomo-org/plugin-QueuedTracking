<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Plugins\QueuedTracking\QueuedTracking;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Plugins\QueuedTracking\Tracker\Handler;
use Piwik\Tracker\Handler as DefaultHandler;

/**
 * @group QueuedTracking
 * @group QueuedTrackingTest
 * @group Plugins
 */
class QueuedTrackingTest extends IntegrationTestCase
{
    /**
     * @var QueuedTracking
     */
    private $plugin;

    public function setUp()
    {
        parent::setUp();
        $this->plugin = new QueuedTracking();

        Factory::getSettings()->queueEnabled->setValue(true);
    }

    public function tearDown()
    {
        Factory::clearSettings();

        parent::tearDown();
    }

    public function test_replaceHandler_ShouldReplaceHandlerWithQueueHandler_IfEnabled()
    {
        $handler = null;
        $this->plugin->replaceHandlerIfQueueIsEnabled($handler);

        $this->assertTrue($handler instanceof Handler);
    }

    public function test_replaceHandler_ShouldEnableProcessingInTrackerModeByDefault()
    {
        $handler = null;
        $this->plugin->replaceHandlerIfQueueIsEnabled($handler);

        $this->assertTrue($handler->isAllowedToProcessInTrackerMode());
    }

    public function test_replaceHandler_ShouldNotReplaceHandlerWithQueueHandler_IfDisabled()
    {
        Factory::getSettings()->queueEnabled->setValue(false);

        $handler = null;
        $this->plugin->replaceHandlerIfQueueIsEnabled($handler);

        $this->assertNull($handler);
    }

    public function test_replaceHandler_ShouldNotEnableProcessingInTrackerModeIfDisabled()
    {
        Factory::getSettings()->processDuringTrackingRequest->setValue(false);

        $handler = null;
        $this->plugin->replaceHandlerIfQueueIsEnabled($handler);

        $this->assertFalse($handler->isAllowedToProcessInTrackerMode());
    }

    public function test_getListHooksRegistered_shouldListenToNewTrackerEventAndCreateQueueHandler()
    {
        $handler = DefaultHandler\Factory::make();

        $this->assertTrue($handler instanceof Handler);
    }

}
