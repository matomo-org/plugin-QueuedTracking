<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Tracker\RequestSet;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;

class Queue
{
    const PREFIX = 'trackingQueueV1';

    /**
     * @var Redis
     */
    private $backend;

    private $key;
    private $id;
    private $numRequestsToProcessInBulk = 50;

    public function __construct(Backend $backend, $id)
    {
        $this->backend = $backend;

        $this->id  = $id;
        $this->key = self::PREFIX;

        if (!empty($id)) {
            $this->key .= '_' . $id;
        }
    }

    public function getId()
    {
        return $this->id;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function setNumberOfRequestsToProcessAtSameTime($numRequests)
    {
        $this->numRequestsToProcessInBulk = $numRequests;
    }

    public function getNumberOfRequestsToProcessAtSameTime()
    {
        return $this->numRequestsToProcessInBulk;
    }

    public function getNumberOfRequestSetsInQueue()
    {
        return $this->backend->getNumValuesInList($this->key);
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

    public function delete()
    {
        return $this->backend->delete($this->key);
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

    public function shouldProcess()
    {
        return $this->backend->hasAtLeastXRequestsQueued($this->key, $this->numRequestsToProcessInBulk);
    }

    public function markRequestSetsAsProcessed()
    {
        $this->backend->removeFirstXValuesFromList($this->key, $this->numRequestsToProcessInBulk);
    }
}
