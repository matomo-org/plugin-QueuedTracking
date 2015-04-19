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

    public function process()
    {
        $tracker = new Tracker();

        if (!$tracker->shouldRecordStatistics()) {
            return $tracker;
        }

        $request = new RequestSet();
        $request->rememberEnvironment();

        $loops = 0;

        try {

            while ($queue = $this->queueManager->lockNext()) {
                if ($loops > $this->numMaxBatchesToProcess) {
                    break;
                } else {
                    $loops++;
                }

                $queuedRequestSets = $queue->getRequestSetsToProcess();

                if (!empty($queuedRequestSets)) {
                    $requestSetsToRetry = $this->processRequestSets($tracker, $queuedRequestSets);
                    $this->processRequestSets($tracker, $requestSetsToRetry);
                    $queue->markRequestSetsAsProcessed();
                    // TODO if markR..() fails, we would process them again later
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
                $this->forceRollbackAndThrowExceptionAsAnotherProcessMightProcessSameRequestSets($tracker);
            }

            try {
                $this->handler->process($tracker, $requestSet);
            } catch (\Exception $e) {
                Common::printDebug('Failed to process a queued request set' . $e->getMessage());
                $this->handler->onException($requestSet, $e);
            }
        }

        if (!$this->extendLockExpireToMakeSureWeCanFinishQueuedRequests($queuedRequestSets)) {
            $this->forceRollbackAndThrowExceptionAsAnotherProcessMightProcessSameRequestSets($tracker);
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
        $ttl = max($ttl, 20); // lock for at least 20 seconds

        return $this->queueManager->expireLock($ttl);
    }

    private function forceRollbackAndThrowExceptionAsAnotherProcessMightProcessSameRequestSets(Tracker $tracker)
    {
        $this->handler->rollBack($tracker);
        throw new LockExpiredException('Rolled back as we no longer have lock or the lock was never acquired');
    }

}
