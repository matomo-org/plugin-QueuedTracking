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

    protected function createRedisBackend()
    {
        Config::getInstance()->QueuedTracking = array('backend' => 'sentinel');

        $sentinel = Factory::makeBackend();

        $this->assertTrue($sentinel instanceof Sentinel);

        return $sentinel;
    }

}
