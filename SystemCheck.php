<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;
use Piwik\Tracker;
use Piwik\Translate;

class SystemCheck
{
    public function checkRedisIsInstalled()
    {
        if (!class_exists('\Redis', false) || !extension_loaded('redis')) {
            throw new \Exception('The phpredis extension is needed. Please check out https://github.com/nicolasff/phpredis');
        }
    }

    public function checkConnectionDetails($host, $port, $timeout, $password)
    {
        $redis = new Redis();
        $redis->setConfig($host, $port, $timeout, $password);

        if (!$redis->testConnection()) {
            throw new \Exception('Connection to Redis failed. Please verify Redis host and port');
        };

        $version = $redis->getServerVersion();

        if (version_compare($version, '2.8.0') < 0) {
            throw new \Exception('At least Redis server 2.8.0 is required');
        }
    }

}
