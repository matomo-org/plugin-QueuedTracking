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

class SystemCheck
{
    public function checkRedisIsInstalled()
    {
        if (!class_exists('\Redis', false) || !extension_loaded('redis')) {
            throw new \Exception('The phpredis extension is needed. Please check out https://github.com/nicolasff/phpredis');
        }
    }

    public function checkConnectionDetails(Redis $backend)
    {
        if (!$backend->testConnection()) {
            throw new \Exception('Connection to Redis failed. Please verify Redis host and port');
        };

        $version = $backend->getServerVersion();

        if (version_compare($version, '2.8.0') < 0) {
            throw new \Exception('At least Redis server 2.8.0 is required');
        }
    }

}
