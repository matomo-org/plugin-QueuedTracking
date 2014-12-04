<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue;

use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Plugins\QueuedTracking\Settings;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group QueuedTracking
 * @group FactoryTest
 * @group Plugins
 */
class FactoryTest extends IntegrationTestCase
{

    public function test_makeQueue_shouldReturnAQueueInstance()
    {
        $queue = Factory::makeQueue($this->createRedisBackend());
        $this->assertTrue($queue instanceof Queue);
    }

    public function test_makeQueue_shouldConfigureTheNumberOfRequestsToProcess()
    {
        Factory::getSettings()->numRequestsToProcess->setValue(34);
        $queue = Factory::makeQueue($this->createRedisBackend());
        $this->assertSame(34, $queue->getNumberOfRequestsToProcessAtSameTime());
    }

    public function test_makeBackend_shouldReturnARedisInstance()
    {
        $backend = Factory::makeBackend();
        $this->assertTrue($backend instanceof Queue\Backend\Redis);
    }

    public function test_makeBackend_shouldConfigureRedis()
    {
        $success = Factory::makeBackend()->testConnection();
        $this->assertTrue($success);
    }

    public function test_getSettings_shouldReturnARedisInstance()
    {
        $settings = Factory::getSettings();
        $this->assertTrue($settings instanceof Settings);
    }

    public function test_getSettings_shouldReturnASingleton()
    {
        $settings = Factory::getSettings();
        $settings->redisTimeout->setValue(0.7);

        // it would not return the same value usually as $settings->save() is not called

        $settings = Factory::getSettings();
        $this->assertEquals(0.7, $settings->redisTimeout->getValue());
    }

}
