<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Tracker\RequestSet;
use Piwik\Tracker;
use Piwik\Translate;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;

class Queue
{
    /**
     * @var Redis
     */
    private $backend;

    private $key = 'trackingQueueV1';
    private $numRequestsToProcessInBulk = 50;

    public function __construct(Backend $backend)
    {
        $this->backend = $backend;
    }

    public function setNumberOfRequestsToProcessAtSameTime($numRequests)
    {
        $this->numRequestsToProcessInBulk = $numRequests;
    }

    public function getNumberOfRequestsToProcessAtSameTime()
    {
        return $this->numRequestsToProcessInBulk;
    }

    public function addRequestSet(RequestSet $requests)
    {
        if (!$requests->hasRequests()) {
            return;
        }

        $value = $requests->getState();
        $value = json_encode($value);

        $this->backend->appendValuesToList($this->key, array($value));
    }

    public function shouldProcess()
    {
        $numRequests = $this->getNumberOfRequestSetsInQueue();

        return $numRequests >= $this->numRequestsToProcessInBulk;
    }

    public function getNumberOfRequestSetsInQueue()
    {
        return $this->backend->getNumValuesInList($this->key);
    }

    /**
     * @return RequestSet[]
     */
    public function getRequestSetsToProcess()
    {
        $values = $this->backend->getFirstXValuesFromList($this->key, $this->numRequestsToProcessInBulk);

        $requests = array();
        foreach ($values as $value) {
            $params = json_decode($value, true);

            $request = new RequestSet();
            $request->restoreState($params);
            $requests[] = $request;
        }

        return $requests;
    }

    public function markRequestSetsAsProcessed()
    {
        $this->backend->removeFirstXValuesFromList($this->key, $this->numRequestsToProcessInBulk);
    }
}
