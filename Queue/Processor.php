<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue;

use Piwik\Common;
use Piwik\Tracker;
use Piwik\Tracker\RequestSet;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor\Handler;
use Exception;

/**
 * Processes all queued tracking requests. You need to acquire a lock before calling process() and unlock it afterwards!
 *
 * eg
 * $processor = new Processor($backend);
 * if ($processor->acquireLock()) {
 *    try {
 *        $processor->process($queue);
 *    } catch (Exception $e) {}
 *    $processor->unlock();
 * }
 *
 * It will process until there are not enough tracking requests in the queue anymore.
 */
class Processor
{
    /**
     * @var Handler
     */
    private $handler;

    /**
     * @var Queue\Manager
     */
    private $queueManager;

    /**
     * The number of batches to process before self-terminating.
     * If this value is 250, and one has configured to insert 25 requests in one batch, 250 * 25 requests will be
     * inserted. This way we prevent eg possible memory problems for when running too long.
     * @var int
     */
    private $numMaxBatchesToProcess = 250;

    public function __construct(Queue\Manager $queueManager)
    {
        $this->queueManager = $queueManager;
        $this->handler = new Handler();
    }

    public function setNumberOfMaxBatchesToProcess($numBatches)
    {
        $this->numMaxBatchesToProcess = (int) $numBatches;
    }

    public function process(Tracker $tracker = null)
    {
        $tracker = $tracker ?: new Tracker();

        if (!$tracker->shouldRecordStatistics()) {
            return $tracker;
        }

        $request = new RequestSet();
        $request->rememberEnvironment();

        $loops = 0;

        try {

            while ($queue = $this->queueManager->lockNext()) {
                Common::printDebug('Acquired lock for queue ' . $queue->getId());

                for ($i = 0; $i < 10; $i++) {
                    // lets run several processings without re-acquiring the lock each time to avoid possible performance
                    // and reduce concurrency issues re the lock. When we have the lock to work on a queue, there is
                    // no need to unlock and get the lock each time... it otherwise becomes quite ineffecient
                    if ($loops > $this->numMaxBatchesToProcess) {
                        Common::printDebug('This worker processed ' . $loops . ' times, stopping now.');
                        $this->queueManager->unlock();
                        break 2;
                    }

                    $loops++;
                    $queuedRequestSets = $queue->getRequestSetsToProcess();

                    if (count($queuedRequestSets) < $queue->getNumberOfRequestsToProcessAtSameTime()) {
                        // could also use `$queue->shouldProcess()` but this is quite a bit faster when there are always
                        // many requests in queue... it is also done this way to prevent potential race conditions...
                        // imagine we call "shouldProcess" and it says there are 60 requests in the queue, now a few ms
                        // we want to take out 50 requests, however, only 10 requests are returned for whatever reason
                        // (should not happen usually as only one job works on a queue).
                        // we need to shop in processing if we don't get enough requests returned, otherwise we would
                        // mark below some requests as processed but they weren't.
                        $this->queueManager->unlock();
                        break 2;
                    }

                    if (!empty($queuedRequestSets)) {
                        $requestSetsToRetry = $this->processRequestSets($tracker, $queuedRequestSets);
                        if (!empty($requestSetsToRetry)) {
                            Common::printDebug('Need to retry ' . count($queuedRequestSets) . ' request sets.');
                        }
                        $this->processRequestSets($tracker, $requestSetsToRetry);
                        $queue->markRequestSetsAsProcessed();
                        // TODO if markR..() fails, we would process them again later
                    }
                }

                $this->queueManager->unlock();
            }

        } catch (Exception $e) {
            Common::printDebug('Failed to process a request set: ' . $e->getMessage());

            $this->queueManager->unlock();
            $request->restoreEnvironment();
            throw $e;
        }

        $request->restoreEnvironment();

        return $tracker;
    }

    /**
     * @param  Tracker $tracker
     * @param  RequestSet[] $queuedRequestSets
     * @return RequestSet[]
     * @throws Exception
     */
    protected function processRequestSets(Tracker $tracker, $queuedRequestSets)
    {
        if (empty($queuedRequestSets)) {
            return array();
        }

        $this->handler->init($tracker);

        foreach ($queuedRequestSets as $index => $requestSet) {
            if (!$this->extendLockExpireToMakeSureWeCanProcessARequestSet($requestSet)) {
                $this->forceRollbackAndThrowExceptionAsAnotherProcessMightProcessSameRequestSets($tracker, 'processing');
            }

            try {
                $this->handler->process($tracker, $requestSet);
            } catch (\Exception $e) {
                Common::printDebug('Failed to process a queued request set' . $e->getMessage());
                $this->handler->onException($requestSet, $e);
            }
        }

        if (!$this->extendLockExpireToMakeSureWeCanFinishQueuedRequests($queuedRequestSets)) {
            $this->forceRollbackAndThrowExceptionAsAnotherProcessMightProcessSameRequestSets($tracker, 'finishing');
        }

        if ($this->handler->hasErrors()) {
            $this->handler->rollBack($tracker);
        } else {
            $this->handler->commit();
        }

        return $this->handler->getRequestSetsToRetry();
    }

    /**
     * @param $queuedRequests
     * @return bool true if we still have the lock and if expire was set successfully
     */
    private function extendLockExpireToMakeSureWeCanFinishQueuedRequests($queuedRequests)
    {
        $ttl = count($queuedRequests) * 2;
        // in case there are 50 queued requests it gives us 100 seconds to commit/rollback and to start new batch

        $ttl = max($ttl, 20); // lock at least for 20 seconds

        return $this->queueManager->expireLock($ttl);
    }

    /**
     * @param RequestSet $requestSet
     * @return bool true if we still have the lock and if expire was set successfully
     */
    private function extendLockExpireToMakeSureWeCanProcessARequestSet(RequestSet $requestSet)
    {
        // 2 seconds per tracking request should give it enough time to process it
        $ttl = $requestSet->getNumberOfRequests() * 2;
        $ttl = max($ttl, 30); // lock for at least 30 seconds

        return $this->queueManager->expireLock($ttl);
    }

    private function forceRollbackAndThrowExceptionAsAnotherProcessMightProcessSameRequestSets(Tracker $tracker, $activity)
    {
        $this->handler->rollBack($tracker);
        throw new LockExpiredException(sprintf("Rolled back during %s as we no longer have lock or the lock was never acquired. So far tracker processed %s requests", $activity, $tracker->getCountOfLoggedRequests()));
    }

}
