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
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;
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
     * @var Redis
     */
    private $backend;

    private $lockKey = 'trackingProcessorLock';
    private $lockValue;

    private $callbackOnProcessNewSet;

    public function __construct(Backend $backend)
    {
        $this->backend = $backend;
        $this->handler = new Handler();
    }

    public function process(Queue $queue)
    {
        $tracker = new Tracker();

        if (!$tracker->shouldRecordStatistics()) {
            return $tracker;
        }

        $request = new RequestSet();
        $request->rememberEnvironment();

        $loops = 0;

        try {

            while ($queue->shouldProcess()) {
                if ($loops > 500) {
                    break;
                } else {
                    $loops++;
                }

                if ($this->callbackOnProcessNewSet) {
                    call_user_func($this->callbackOnProcessNewSet, $queue, $tracker);
                }

                $queuedRequestSets = $queue->getRequestSetsToProcess();

                if (!empty($queuedRequestSets)) {
                    $requestSetsToRetry = $this->processRequestSets($tracker, $queuedRequestSets);
                    $this->processRequestSets($tracker, $requestSetsToRetry);
                    $queue->markRequestSetsAsProcessed();
                    // TODO if markR..() fails, we would process them again later
                }
            }

        } catch (Exception $e) {
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
     * @param \Callable $callback
     */
    public function setOnProcessNewRequestSetCallback($callback)
    {
        $this->callbackOnProcessNewSet = $callback;
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

        return $this->expireLock($ttl);
    }

    /**
     * @param RequestSet $requestSet
     * @return bool true if we still have the lock and if expire was set successfully
     */
    private function extendLockExpireToMakeSureWeCanProcessARequestSet(RequestSet $requestSet)
    {
        // 2 seconds per request set should give it enough time to process it
        $ttl = $requestSet->getNumberOfRequests() * 2;
        $ttl = max($ttl, 20); // lock for at least 20 seconds

        return $this->expireLock($ttl);
    }

    public function acquireLock()
    {
        if (!$this->lockValue) {
            $this->lockValue = substr(Common::generateUniqId(), 0, 12);
        }

        $locked = $this->backend->setIfNotExists($this->lockKey, $this->lockValue, $ttlInSeconds = 60);

        return $locked;
    }

    public function unlock()
    {
        $this->backend->deleteIfKeyHasValue($this->lockKey, $this->lockValue);
        $this->lockValue = null;
    }

    private function expireLock($ttlInSeconds)
    {
        if ($ttlInSeconds > 0) {
            return $this->backend->expireIfKeyHasValue($this->lockKey, $this->lockValue, $ttlInSeconds);
        }

        return false;
    }

    private function forceRollbackAndThrowExceptionAsAnotherProcessMightProcessSameRequestSets(Tracker $tracker)
    {
        $this->handler->rollBack($tracker);
        throw new LockExpiredException('Rolled back as we no longer have lock or the lock was never acquired');
    }

}
