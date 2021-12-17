<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue\Processor;

use Piwik\Common;
use Piwik\Db;
use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Tracker;
use Piwik\Tracker\RequestSet;
use Exception;

class Handler
{
    protected $transactionId;

    private $hasError = false;
    private $requestSetsToRetry = array();
    private $count = 0;
    private $numTrackedRequestsBeginning = 0;

    public function init(Tracker $tracker)
    {
        $this->requestSetsToRetry = array();
        $this->hasError = false;
        $this->numTrackedRequestsBeginning = $tracker->getCountOfLoggedRequests();
        $this->transactionId = $this->getDb()->beginTransaction();
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        $requestSet->restoreEnvironment();

        $this->count = 0;

        foreach ($requestSet->getRequests() as $request) {
            try {
                $startMs = round(microtime(true) * 1000);

                $tracker->trackRequest($request);

                $diffInMs = round(microtime(true) * 1000) - $startMs;
                if ($diffInMs > 2000) {
                    Common::printDebug(sprintf('The following request took more than 2 seconds (%d ms) to be tracked: %s', $diffInMs, var_export($request->getParams(), 1)));
                }

                $this->count++;
            } catch (UnexpectedWebsiteFoundException $ex) {
                // empty
            }
        }

        $this->requestSetsToRetry[] = $requestSet;
    }

    public function onException(RequestSet $requestSet, Exception $e)
    {
        // todo: how do we want to handle DbException or RedisException?
        $this->hasError = true;

        Common::printDebug('Got exception: ' . $e->getMessage());

        // retry if a deadlock or a lock wait timeout happened
        if (Db::get()->isErrNo($e, 1213) || Db::get()->isErrNo($e, 1205)) {
            $this->requestSetsToRetry[] = $requestSet;
            Common::printDebug('Added deadlocked requestSet to requestSetsToRetry');
            return;
        }

        if ($this->count > 0) {
            // remove the first one that failed and all following (standard bulk tracking behavior)
            $insertedRequests = array_slice($requestSet->getRequests(), 0, $this->count);
            $requestSet->setRequests($insertedRequests);
            $this->requestSetsToRetry[] = $requestSet;
        }
    }

    public function hasErrors()
    {
        return $this->hasError;
    }

    public function rollBack(Tracker $tracker)
    {
        $tracker->setCountOfLoggedRequests($this->numTrackedRequestsBeginning);
        $this->getDb()->rollBack($this->transactionId);
    }

    /**
     * @return RequestSet[]
     */
    public function getRequestSetsToRetry()
    {
        return $this->requestSetsToRetry;
    }

    public function commit()
    {
        $this->getDb()->commit($this->transactionId);
        $this->requestSetsToRetry = array();
    }

    protected function getDb()
    {
        return Tracker::getDatabase();
    }

}
