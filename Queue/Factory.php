<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue;

use Piwik\Container\StaticContainer;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\SystemSettings;
use Exception;

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

    /**
     * @return \Piwik\Plugins\QueuedTracking\SystemSettings
     */
    public static function getSettings()
    {
        return StaticContainer::get('Piwik\Plugins\QueuedTracking\SystemSettings');
    }

    public static function makeBackendFromSettings(SystemSettings $settings)
    {
        if ($settings->isMysqlBackend()) {
            return new Queue\Backend\MySQL();
        }

        $host     = $settings->redisHost->getValue();
        $port     = $settings->redisPort->getValue();
        $timeout  = $settings->redisTimeout->getValue();
        $password = $settings->redisPassword->getValue();
        $database = $settings->redisDatabase->getValue();

        if ($settings->isUsingSentinelBackend()) {
            $masterName = $settings->getSentinelMasterName();
            if (empty($masterName)) {
                throw new Exception('You must configure a sentinel master name via `sentinel_master_name="mymaster"` to use the sentinel backend');
            } else {
                $redis = new Queue\Backend\Sentinel();
                $redis->setSentinelMasterName($masterName);
            }
        } else {
            $redis = new Queue\Backend\Redis();
        }

        $redis->setConfig($host, $port, $timeout, $password);
        $redis->setDatabase($database);

        return $redis;
    }

}
