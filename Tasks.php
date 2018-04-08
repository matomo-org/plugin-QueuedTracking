<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\QueuedTracking\Queue\Backend\MySQL;

class Tasks extends \Piwik\Plugin\Tasks
{
    public function schedule()
    {
        $this->daily('optimizeQueueTable', null, self::LOWEST_PRIORITY);
    }

    /**
     * run eg using ./console core:run-scheduled-tasks "Piwik\Plugins\QueuedTracking\Tasks.optimizeQueueTable"
     */
    public function optimizeQueueTable()
    {
        $settings = Queue\Factory::getSettings();
        if ($settings->isMysqlBackend() && $settings->queueEnabled->getValue()) {

            $db = Db::get();
            $prefix = Common::prefixTable(MySQL::QUEUED_TRACKING_TABLE_PREFIX);
            $tables = $db->fetchCol("SHOW TABLES LIKE '" . $prefix . "%'");

            $force = Db::isOptimizeInnoDBSupported();
            // if supported, then we want to force it, as it is quite important to execute this
            Db::optimizeTables($tables, $force);
        }
    }

}