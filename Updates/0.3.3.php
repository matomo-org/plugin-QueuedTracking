<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking;

use Piwik\Container\StaticContainer;
use Piwik\Timer;
use Piwik\Updater;
use Piwik\Updates;

class Updates_0_3_3 extends Updates
{
    public function doUpdate(Updater $updater)
    {
        $settings = $this->getSettings();
        $numQueueWorkers = $settings->numQueueWorkers->getValue();

        // queues are numbered from 0 to (number of queues - 1)
        $queueIdsToMove = range(0, $numQueueWorkers - 1);

        $backend      = Queue\Factory::makeBackend();
        $queueManager = Queue\Factory::makeQueueManager($backend);

        $t = new Timer();
        $t->init();

        $queueManager->moveRequestsIntoAnotherQueue($queueIdsToMove);

        echo $t->getTime();
    }

    /**
     * @return Settings
     */
    public function getSettings()
    {
        return StaticContainer::get('Piwik\Plugins\QueuedTracking\Settings');
    }
}
