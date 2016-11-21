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
use Piwik\Plugins\QueuedTracking\Queue\Manager;
use Piwik\Updater;
use Piwik\Updates;

class Updates_0_3_3 extends Updates
{
    /**
     * @var Manager
     */
    protected $queueManager;

    public function doUpdate(Updater $updater)
    {
        $settings = $this->getSettings();
        $numQueueWorkers = $settings->numQueueWorkers->getValue();

        if ($numQueueWorkers <= 1) {
            return; // it is pointless to rewrite one queue to the same queue
        }

        $this->rewriteQueues($numQueueWorkers);
    }

    /**
     * @return Settings
     */
    public function getSettings()
    {
        return StaticContainer::get('Piwik\Plugins\QueuedTracking\Settings');
    }

    private function rewriteQueues($numQueueWorkers)
    {
        // queues are numbered from 0 to (number of queues - 1)
        $queueIdsToRewrite = range(0, $numQueueWorkers - 1);

        // loading all requests and removing them from queues
        $requests = $this->loadRequestsFromQueues($queueIdsToRewrite);

        // writes requests to queues using new algorithm
        $this->writeQueues($requests);
    }

    /**
     * @return Manager
     */
    private function getQueueManager()
    {
        if (!$this->queueManager) {
            $backend = Queue\Factory::makeBackend();
            $this->queueManager = Queue\Factory::makeQueueManager($backend);
        }

        return $this->queueManager;
    }

    /**
     * @param array $queueIdsToRewrite
     * @return array
     */
    private function loadRequestsFromQueues(array $queueIdsToRewrite)
    {
        $queueManager = $this->getQueueManager();

        $requestsByQueueId = [];

        foreach ($queueIdsToRewrite as $queueId) {
            $queue = $queueManager->createQueue($queueId);

            $singleQueueRequestsSets = [];

            while ($requestSets = $queue->getRequestSetsToProcess()) {
                $singleQueueRequestsSets[] = $requestSets;
                $queue->markRequestSetsAsProcessed();
            }

            $requestsByQueueId[$queueId] = $singleQueueRequestsSets;
        }

        return $requestsByQueueId;
    }

    private function writeQueues(array $requestsByQueueId)
    {
        $queueManager = $this->getQueueManager();

        foreach ($requestsByQueueId as $queueId => $requestSets) {
            foreach ($requestSets as $requestsSubSet) {
                foreach ($requestsSubSet as $requestSet) {
                    $queueManager->addRequestSetToQueues($requestSet);
                }
            }
        }
    }
}
