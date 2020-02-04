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
        parent::__construct();
        $this->setResponse(new Response());
    }

    // here we write add the tracking requests to a list
    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        $queueManager = $this->getQueueManager();
        $queueManager->addRequestSetToQueues($requestSet);
        $tracker->setCountOfLoggedRequests($requestSet->getNumberOfRequests());

        $requests = $requestSet->getRequests();
        foreach ($requests as $request) {
            $request->setThirdPartyCookie($request->getVisitorIdForThirdPartyCookie());
        }

        $this->sendResponseNow($tracker, $requestSet);

        if ($this->isAllowedToProcessInTrackerMode() && $queueManager->canAcquireMoreLocks()) {
            $this->processQueue($queueManager);
        }
    }

    private function sendResponseNow(Tracker $tracker, RequestSet $requestSet)
    {
        $response = $this->getResponse();
        $response->outputResponse($tracker);
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

    private function processQueue(Queue\Manager $queueManager)
    {
        Common::printDebug('We are going to process the queue');
        set_time_limit(0);

        try {
            $processor = new Processor($queueManager);
            $processor->process();
        } catch (Exception $e) {
            Common::printDebug('Failed to process queue: ' . $e->getMessage());
            // TODO how could we report errors better as the response is already sent? also monitoring ...
        }

        $queueManager->unlock();
    }

    private function getBackend()
    {
        if (is_null($this->backend)) {
            $this->backend = Queue\Factory::makeBackend();
        }

        return $this->backend;
    }

    private function getQueueManager()
    {
        $backend = $this->getBackend();
        $queue   = Queue\Factory::makeQueueManager($backend);

        return $queue;
    }

    public function finish(Tracker $tracker, RequestSet $requestSet)
    {
        return $this->getResponse()->getOutput();
    }
}
