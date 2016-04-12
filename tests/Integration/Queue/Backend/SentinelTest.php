<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue\Backend;
use Piwik\Config;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Sentinel;
use Piwik\Plugins\QueuedTracking\Queue\Factory;

/**
 * @group QueuedTracking
 * @group Redis
 * @group RedisTest
 * @group Queue
 * @group Tracker
 */
class SentinelTest extends RedisTest
{

    public function tearDown()
    {
        Config::getInstance()->QueuedTracking = array();
        parent::tearDown();
    }

    protected function createRedisBackend()
    {
        $settings = Factory::getSettings();

        Config::getInstance()->QueuedTracking = array();

        $this->assertFalse($settings->isUsingSentinelBackend());
        $this->assertNull($settings->getSentinelMasterName());

        Config::getInstance()->QueuedTracking = array('backend' => 'sentinel', 'sentinel_master_name' => 'mymaster');

        $this->assertTrue($settings->isUsingSentinelBackend());
        $this->assertSame('mymaster', $settings->getSentinelMasterName());

        $settings->redisPort->setValue(26379);

        $sentinel = Factory::makeBackend();

        $this->assertTrue($sentinel instanceof Sentinel);

        return $sentinel;
    }

}
