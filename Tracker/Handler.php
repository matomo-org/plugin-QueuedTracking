<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Tracker;

use Piwik\Common;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Tracker\RequestSet;
use Exception;
use Piwik\Url;

/**
 * @method Response getResponse()
 */
class Handler extends Tracker\Handler
{
    /**
     * @var Backend
     */
    private $backend;

    private $isAllowedToProcessInTrackerMode = false;

    public function __construct()
    {
        $this->setResponse(new Response());
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        $queue = $this->getQueue();
        $queue->addRequestSet($requestSet);
        $tracker->setCountOfLoggedRequests($requestSet->getNumberOfRequests());

        $this->sendResponseNow($tracker, $requestSet);

        if ($this->isAllowedToProcessInTrackerMode()) {
            $this->processQueueIfNeeded($queue);
        }
    }

    private function sendResponseNow(Tracker $tracker, RequestSet $requestSet)
    {
        $response = $this->getResponse();
        $response->outputResponse($tracker);
        $this->redirectIfNeeded($requestSet);
        $response->sendResponseToBrowserDirectly();
    }

    /**
     * @internal
     */
    public function isAllowedToProcessInTrackerMode()
    {
        return $this->isAllowedToProcessInTrackerMode;
    }

    public function enableProcessingInTrackerMode()
    {
        $this->isAllowedToProcessInTrackerMode = true;
    }

    private function processQueueIfNeeded(Queue $queue)
    {
        if ($queue->shouldProcess()) {
            $backend   = $this->getBackend();
            $processor = new Processor($backend);

            $this->processIfNotLocked($processor, $queue);
        }
    }

    private function processIfNotLocked(Processor $processor, Queue $queue)
    {
        if ($processor->acquireLock()) {

            Common::printDebug('We are going to process the queue');
            set_time_limit(0);

            try {
                $processor->process($queue);
            } catch (Exception $e) {
                Common::printDebug('Failed to process queue: ' . $e->getMessage());
                // TODO how could we report errors better as the response is already sent? also monitoring ...
            }

            $processor->unlock();
        }
    }

    private function getBackend()
    {
        if (is_null($this->backend)) {
            $this->backend = Queue\Factory::makeBackend();
        }

        return $this->backend;
    }

    private function getQueue()
    {
        $backend = $this->getBackend();
        $queue   = Queue\Factory::makeQueue($backend);

        return $queue;
    }

}
