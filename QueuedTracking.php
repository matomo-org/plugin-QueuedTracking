<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking;

use Piwik\Common;
use Piwik\Plugins\QueuedTracking\Queue\Backend\MySQL;
use Piwik\Plugins\QueuedTracking\Tracker\Handler;

class QueuedTracking extends \Piwik\Plugin
{
    /**
     * @see \Piwik\Plugin::registerEvents
     */
    public function registerEvents()
    {
        return array(
            'Tracker.newHandler'    => 'replaceHandlerIfQueueIsEnabled',
            'Db.getTablesInstalled' => 'getTablesInstalled'
        );
    }

    /**
     * Register the new tables, so Matomo knows about them.
     *
     * @param array $allTablesInstalled
     */
    public function getTablesInstalled(&$allTablesInstalled)
    {
        $allTablesInstalled[] = Common::prefixTable('queuedtracking_queue');
    }

    public function install()
    {
        $mysql = new MySQL();
        $mysql->install();

        $configuration = new Configuration();
        $configuration->install();
    }

    public function uninstall()
    {
        $mysql = new MySQL();
        $mysql->uninstall();

        $configuration = new Configuration();
        $configuration->uninstall();
    }

    public function isTrackerPlugin()
    {
        return true;
    }

    public function replaceHandlerIfQueueIsEnabled(&$handler)
    {
        $useQueuedTracking = Common::getRequestVar('queuedtracking', 1, 'int');
        if (!$useQueuedTracking) {
            return;
        }

        $settings = Queue\Factory::getSettings();

        if ($settings->queueEnabled->getValue()) {
            $handler = new Handler();

            if ($settings->processDuringTrackingRequest->getValue()) {
                $handler->enableProcessingInTrackerMode();
            }
        }
    }
}
