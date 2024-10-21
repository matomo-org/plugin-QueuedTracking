<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
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
    public function tearDown(): void
    {
        Config::getInstance()->QueuedTracking = [];
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

    public function test_connect_ShouldThrowException_IfNotExactSameHostAndPortNumbersGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('QueuedTracking_NumHostsNotMatchNumPorts');

        $this->enableRedisSentinel();

        $settings = Factory::getSettings();
        $this->assertTrue($settings->isUsingSentinelBackend());

        $settings->redisHost->setValue('127.0.0.1,127.0.0.1');
        $settings->redisPort->setValue('26378,26379,26379');

        $sentinel = Factory::makeBackendFromSettings($settings);
        $sentinel->get('test');
    }
}
