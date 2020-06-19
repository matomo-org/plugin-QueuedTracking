<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
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

    /**
     * The minimum number of times to loop through all queues before exiting due to an empty queue.
     *
     * This value is a trade-off between fast execution when the queues are near-empty; and 'fair' processing of all
     * queues.
     *
     * A value of zero will stop on the first empty queue.
     * @var int
     */
    private $numMinQueueIterations = 2;

    public function __construct(Queue\Manager $queueManager)
    {
        $this->queueManager = $queueManager;
        $this->handler = new Handler();
    }

    public function setNumberOfMaxBatchesToProcess($numBatches)
    {
        $this->numMaxBatchesToProcess = (int) $numBatches;
    }

    public function setNumberOfMinQueueIterations($numMinQueueIterations)
    {
        $this->numMinQueueIterations = (int) $numMinQueueIterations;
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

        // Records the queue ID and the number of times we've seen this queue and it's been empty.
        $emptyQueueVisitCounts = array();

        try {

            while ($queue = $this->queueManager->lockNext()) {
                Common::printDebug('Acquired lock for queue ' . $queue->getId());

                for ($i = 0; $i < 10; $i++) {
                    // lets run several processings without re-acquiring the lock each time to avoid possible performance
                    // and reduce concurrency issues re the lock. When we have the lock to work on a queue, there is
                    // no need to unlock and get the lock each time... it otherwise becomes quite inefficient
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
                        if (!isset($emptyQueueVisitCounts[$queue->getId()])) {
                            $emptyQueueVisitCounts[$queue->getId()] = 1;
                        } else {
                            $emptyQueueVisitCounts[$queue->getId()] += 1;
                        }
                        if ($emptyQueueVisitCounts[$queue->getId()] <= $this->numMinQueueIterations) {
                            // We have got a near-empty queue; but we haven't visited it the required number of times
                            // to ensure we have visited all the queues at least `$this->numMinQueueIterations` times.
                            // Stop processing this queue and move on.
                            // Unlocking is not necessary here as it is done just after the for loop.
                            break;
                        } else {
                            // Visited this near-empty queue enough times to have processed each queue at least
                            // `$this->numMinQueueIterations` times; we can now exit whilst ensuring we are also
                            // giving the other queues a chance to empty.
                            $this->queueManager->unlock();
                            break 2;
                        }
                    }

                    if (!empty($queuedRequestSets)) {
                        $requestSetsToRetry = $this->processRequestSets($tracker, $queuedRequestSets);
                        if (!empty($requestSetsToRetry)) {
                            Common::printDebug('Need to retry ' . count($queuedRequestSets) . ' request sets.');
                        }

                        $failedRequestSets = $this->processRequestSets($tracker, $requestSetsToRetry);
                        if (!empty($failedRequestSets)) {
                            Common::printDebug('Failed processing ' . count($failedRequestSets) . ' request sets.');
                        }

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
