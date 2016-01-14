<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue;

use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Settings;
use Piwik\Tracker\SettingsStorage;

/**
 * This class represents a page view, tracking URL, page title and generation time.
 */
class Factory
{
    public static function makeBackend()
    {
        $settings = self::getSettings();

        return self::makeBackendFromSettings($settings);
    }

    public static function makeQueueManager(Backend $backend)
    {
        $settings = self::getSettings();

        $lock    = self::makeLock($backend);
        $manager = new Manager($backend, $lock);
        $manager->setNumberOfAvailableQueues($settings->numQueueWorkers->getValue());
        $manager->setNumberOfRequestsToProcessAtSameTime($settings->numRequestsToProcess->getValue());

        return $manager;
    }

    public static function makeLock(Backend $backend)
    {
        return new Lock($backend);
    }

    public static function getSettings()
    {
        return StaticContainer::get('Piwik\Plugins\QueuedTracking\Settings');
    }

    private static function getConfig()
    {
        return Config::getInstance();
    }

    private static function makeBackendFromSettings(Settings $settings)
    {
        $host     = $settings->redisHost->getValue();
        $port     = $settings->redisPort->getValue();
        $timeout  = $settings->redisTimeout->getValue();
        $password = $settings->redisPassword->getValue();
        $database = $settings->redisDatabase->getValue();

        $queuedTracking = self::getConfig()->QueuedTracking;
        if (!empty($queuedTracking['backend']) && $queuedTracking['backend'] === 'sentinel') {
            $redis = new Queue\Backend\Sentinel();
        } else {
            $redis = new Queue\Backend\Redis();
        }

        $redis->setConfig($host, $port, $timeout, $password);
        $redis->setDatabase($database);

        return $redis;
    }

}
