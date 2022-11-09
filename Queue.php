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
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;

class Queue
{
    const PREFIX = 'trackingQueueV1';
    const JSON_STRING_PARAMS = [
        'uadata'
    ];

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
            $this->ensureJsonVarsAreStrings($params);

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

    /**
     * Check to make sure that we don't have any params that have already been decoded when a JSON string is expected.
     * The request array is passed by reference so that any changes are made to the original array. If any params are
     * found, this simply encodes them into a JSON string again.
     *
     * @param array $requestArray
     * @return void
     */
    public function ensureJsonVarsAreStrings(&$requestArray)
    {
        if (!is_array($requestArray) || !is_array($requestArray['requests']) || empty($requestArray['requests'][0])) {
            return;
        }

        $params = $requestArray['requests'][0];
        foreach (self::JSON_STRING_PARAMS as $var) {
            if (!empty($params[$var]) && !is_string($params[$var])) {
                $requestArray['requests'][0][$var] = json_encode($params[$var]);
            }
        }
    }
}
