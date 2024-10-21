<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue;

use Piwik\Exception\InvalidRequestParameterException;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Tracker\RequestSet;
use Piwik\Tracker;

class Manager
{
    /**
     * @var Backend
     */
    private $backend;

    /**
     * @var Lock
     */
    private $lock;

    /**
     * @var int
     */
    private $currentQueueId  = -1;

    private $numQueuesAvailable = 4;

    private $numRequestsToProcessInBulk = 50;

    private $forceQueueId;

    /**
     * This mapping makes sure we move requests more evenly into different queues. Eg if we would do a
     * ord($firstLetter) instead of this mapping, and have 4 workers, we would move the following characters:
     * '0, 4, 8, d' into queue 0, '1, 5, 9, a, e' into queue 1, '2, 6, b, f' into queue 2 and '3, 7, c' into queue 3.
     * This means queue 0 would get way more requests assigned compared to queue 3. It won't be perfect this way eg
     * when having only 3 workers there will be always one queue with a few more results.
     *
     * @var array
     */
    private $mappingLettersToNumeric = array(
        '0' => 0,
        '1' => 1,
        '2' => 2,
        '3' => 3,
        '4' => 4,
        '5' => 5,
        '6' => 6,
        '7' => 7,
        '8' => 8,
        '9' => 9,
        'a' => 10,
        'b' => 11,
        'c' => 12,
        'd' => 13,
        'e' => 14,
        'f' => 15,
    );

    public function __construct(Backend $backend, Lock $lock)
    {
        $this->backend = $backend;
        $this->lock    = $lock;
    }

    public function setForceQueueId($queueId)
    {
        $this->forceQueueId = $queueId;
    }

    public function setNumberOfAvailableQueues($numQueues)
    {
        $this->numQueuesAvailable = (int) $numQueues;
    }

    public function getNumberOfAvailableQueues()
    {
        return $this->numQueuesAvailable;
    }

    public function moveSomeQueuesIfNeeded($newNumberOfAvailableWorkers, $oldNumberOfAvailableWorkers)
    {
        if ($newNumberOfAvailableWorkers >= $oldNumberOfAvailableWorkers) {
            // not needed to move requests into another queue
            return false;
        }

        $queueIdsToMove = range($newNumberOfAvailableWorkers, $oldNumberOfAvailableWorkers);

        $this->setNumberOfAvailableQueues($newNumberOfAvailableWorkers);
        $this->moveRequestsIntoAnotherQueue($queueIdsToMove);

        return true;
    }

    private function moveRequestsIntoAnotherQueue($queueIdsToMove)
    {
        foreach ($queueIdsToMove as $queueId) {
            $queue = $this->createQueue($queueId);

            while ($requestSets = $queue->getRequestSetsToProcess()) {
                foreach ($requestSets as $requestSet) {
                    $this->addRequestSetToQueues($requestSet);
                }

                $queue->markRequestSetsAsProcessed();
            }

            $queue->delete();
        }
    }

    public function setNumberOfRequestsToProcessAtSameTime($numRequests)
    {
        $this->numRequestsToProcessInBulk = $numRequests;
    }

    public function getNumberOfRequestsToProcessAtSameTime()
    {
        return $this->numRequestsToProcessInBulk;
    }

    public function addRequestSetToQueues(RequestSet $requestSet)
    {
        /** @var RequestSet[][] $queues */
        $queues = array();

        // make sure the requests within a bulk request go into the correct queue
        foreach ($requestSet->getRequests() as $request) {
            $visitorId = $this->getVisitorIdFromRequest($request);
            $queueId   = $this->getQueueIdForVisitor($visitorId);

            if (!isset($queues[$queueId])) {
                $queues[$queueId] = array();
            }

            $queues[$queueId][] = $request;
        }

        foreach ($queues as $queueId => $requests) {
            $requestSet->setRequests($requests);

            $queue = $this->createQueue($queueId);
            $queue->addRequestSet($requestSet);
        }
    }

    public function getNumberOfRequestSetsInAllQueues()
    {
        $total = 0;

        foreach ($this->getAllQueues() as $queue) {
            $total += $queue->getNumberOfRequestSetsInQueue();
        }

        return $total;
    }

    /**
     * External use only for tests
     * @internal
     *
     * @param int $id
     * @return Queue
     */
    public function createQueue($id)
    {
        $queue = new Queue($this->backend, $id);
        $queue->setNumberOfRequestsToProcessAtSameTime($this->numRequestsToProcessInBulk);
        return $queue;
    }

    /**
     * @return Queue[]
     */
    public function getAllQueues()
    {
        $queues = array();

        for ($i = 0; $i < $this->numQueuesAvailable; $i++) {
            $queues[] = $this->createQueue($i);
        }

        return $queues;
    }

    private function getVisitorIdFromRequest(Tracker\Request $request)
    {
        try {
            $visitorId = $request->getVisitorId();
        } catch (InvalidRequestParameterException $e) {
            $visitorId = null;
        }

        if (empty($visitorId)) {
            // we create a md5 otherwise IP's starting with 1 or 2 would be likely moved into same queue
            $visitorId = md5($request->getIpString());
        } else {
            $visitorId = bin2hex($visitorId);
        }

        return $visitorId;
    }

    protected function getQueueIdForVisitor($visitorId)
    {
        $visitorId = strtolower(substr($visitorId, 0, 3));
        if (ctype_xdigit($visitorId) === true) {
            $id = hexdec($visitorId);
        } else {
            $pos1 = ord($visitorId);
            $pos2 = isset($visitorId[1]) ? ord($visitorId[1]) : $pos1;
            $pos3 = isset($visitorId[2]) ? ord($visitorId[2]) : $pos2;
            $id = $pos1 + $pos2 + $pos3;
        }

        return $id % $this->numQueuesAvailable;
    }

    public function canAcquireMoreLocks()
    {
        return $this->lock->getNumberOfAcquiredLocks() < $this->numQueuesAvailable;
    }

    private function getRandomQueueId()
    {
        static $useMtRand;

        if (!isset($useMtRand)) {
            $useMtRand = function_exists('mt_rand');
        }

        if ($useMtRand) {
            $rand = mt_rand(0, $this->numQueuesAvailable - 1);
        } else {
            $rand = rand(0, $this->numQueuesAvailable - 1);
        }

        return $rand;
    }

    /**
     * @return Queue
     */
    public function lockNext()
    {
        $this->unlock();

        if (isset($this->forceQueueId) && $this->forceQueueId <= $this->numQueuesAvailable) {
            $queue = $this->createQueue($this->forceQueueId);

            $shouldProcess = $queue->shouldProcess();

            if ($shouldProcess && $this->lock->acquireLock($this->forceQueueId)) {
                return $queue;
            }

            // do not try to acquire a different lock
            return;
        }

        if ($this->currentQueueId < 0) {
            // we just want to avoid to always start looking for the queue at position 0
            $this->currentQueueId = $this->getRandomQueueId();
        }

        // here we look for all available queues whether at least one should and can be processed

        $start = $this->currentQueueId + 1; // this way we make sure to rotate through all queues
        $end   = $start + $this->numQueuesAvailable;

        for (; $start < $end; $start++) {
            $this->currentQueueId = $start % $this->numQueuesAvailable;
            $queue = $this->createQueue($this->currentQueueId);

            $shouldProcess = $queue->shouldProcess();

            if ($shouldProcess && $this->lock->acquireLock($this->currentQueueId)) {
                return $queue;
            }
        }
    }

    public function unlock()
    {
        $this->lock->unlock();
    }

    public function expireLock($ttlInSeconds)
    {
        return $this->lock->expireLock($ttlInSeconds);
    }
}
