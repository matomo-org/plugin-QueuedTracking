<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue;

use Piwik\Config;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Plugins\QueuedTracking\SystemSettings;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group QueuedTracking
 * @group FactoryTest
 * @group Plugins
 */
class FactoryTest extends IntegrationTestCase
{

    public function test_makeQueueManager_shouldReturnAQueueInstance()
    {
        $queue = Factory::makeQueueManager($this->createRedisBackend());
        $this->assertTrue($queue instanceof Queue\Manager);
    }

    public function test_makeQueueMananger_shouldConfigureTheNumberOfRequestsToProcess()
    {
        Factory::getSettings()->numRequestsToProcess->setValue(31);
        $queue = Factory::makeQueueManager($this->createRedisBackend());
        $this->assertSame(31, $queue->getNumberOfRequestsToProcessAtSameTime());
    }

    public function test_makeQueueMananger_shouldConfigureTheNumberOfWorkers()
    {
        $redis = $this->createRedisBackend();
        Factory::getSettings()->numQueueWorkers->setValue(7);

        $queue = Factory::makeQueueManager($redis);
        $this->assertSame(7, $queue->getNumberOfAvailableQueues());
    }

    public function test_makeLock_shouldReturnALockInstance()
    {
        $backend = Factory::makeBackend();
        $lock = Factory::makeLock($backend);
        $this->assertTrue($lock instanceof Queue\Lock);
    }

    public function test_makeBackend_shouldReturnARedisInstance()
    {
        $backend = Factory::makeBackend();
        $this->assertTrue($backend instanceof Queue\Backend\Redis);
        $this->assertFalse($backend instanceof Queue\Backend\Sentinel);
    }

    public function test_makeBackend_shouldReturnASentinelInstanceIfConfigured()
    {
        Config::getInstance()->QueuedTracking = array('backend' => 'sentinel');
        $backend = Factory::makeBackend();
        Config::getInstance()->QueuedTracking = array();
        $this->assertTrue($backend instanceof Queue\Backend\Sentinel);
    }

    public function test_makeBackend_shouldConfigureRedis()
    {
        $success = Factory::makeBackend()->testConnection();
        $this->assertTrue($success);
    }

    public function test_getSettings_shouldReturnARedisInstance()
    {
        $settings = Factory::getSettings();
        $this->assertTrue($settings instanceof SystemSettings);
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
