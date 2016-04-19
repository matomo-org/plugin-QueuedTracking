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
    public function setUp()
    {
        if (self::isTravisCI()) {
            $this->markTestSkipped('Sentinel is not installed on travis');
        }
        parent::setUp();
    }

    public function tearDown()
    {
        Config::getInstance()->QueuedTracking = array();
        parent::tearDown();
    }

    protected function createRedisBackend()
    {
        $settings = Factory::getSettings();

        $this->enableRedisSentinel();
        $this->assertTrue($settings->isUsingSentinelBackend());

        $settings->redisPort->setValue('26379');

        $sentinel = Factory::makeBackend();

        $this->assertTrue($sentinel instanceof Sentinel);

        return $sentinel;
    }

    public function test_canCreateInstanceWithMultipleSentinelAndFallback()
    {
        $settings = Factory::getSettings();

        $this->enableRedisSentinel();
        $this->assertTrue($settings->isUsingSentinelBackend());

        $settings->redisHost->setValue('127.0.0.1,127.0.0.2,127.0.0.1');
        $settings->redisPort->setValue('26378,26379,26379');

        $sentinel = Factory::makeBackendFromSettings($settings);
        $this->assertTrue($sentinel->testConnection());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage QueuedTracking_NumHostsNotMatchNumPorts
     */
    public function test_connect_ShouldThrowException_IfNotExactSameHostAndPortNumbersGiven()
    {
        $this->enableRedisSentinel();

        $settings = Factory::getSettings();
        $this->assertTrue($settings->isUsingSentinelBackend());

        $settings->redisHost->setValue('127.0.0.1,127.0.0.1');
        $settings->redisPort->setValue('26378,26379,26379');

        $sentinel = Factory::makeBackendFromSettings($settings);
        $sentinel->get('test');
    }

}
