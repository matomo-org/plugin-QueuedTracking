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
use Piwik\Container\StaticContainer;
use Piwik\Exception\InvalidRequestParameterException;
use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Plugins\QueuedTracking\Configuration;
use Piwik\Tracker;
use Piwik\Tracker\Db\DbException;
use Piwik\Tracker\RequestSet;
use Exception;

class Handler
{
    protected $transactionId;

    private $hasError = false;
    private $requestSetsWithTransactionErrors = [];
    private $requestSetsToRetry = array();
    /**
     * @var Tracker\Request[] the requests of the current request set that were tracked
     */
    private $trackedRequests = [];
    private $numTrackedRequestsBeginning = 0;

    public function init(Tracker $tracker)
    {
        $this->requestSetsToRetry = array();
        $this->hasError = false;
        $this->requestSetsWithTransactionErrors = [];
        $this->numTrackedRequestsBeginning = $tracker->getCountOfLoggedRequests();
        $this->transactionId = $this->getDb()->beginTransaction();
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        $this->trackedRequests = [];

        $requestSet->restoreEnvironment();

        foreach ($requestSet->getRequests() as $request) {
            try {
                $startMs = round(microtime(true) * 1000);

                $tracker->trackRequest($request);

                $diffInMs = round(microtime(true) * 1000) - $startMs;
                if ($diffInMs > 2000) {
                    Common::printDebug(sprintf('The following request took more than 2 seconds (%d ms) to be tracked: %s', $diffInMs, var_export($request->getParams(), true)));
                }

                $this->trackedRequests[] = $request;
            } catch (UnexpectedWebsiteFoundException $ex) {
                // empty
            } catch (InvalidRequestParameterException $ex) {
                // rejected like in a bulk request, so only this request is left out
                StaticContainer::get(\Piwik\Log\LoggerInterface::class)->debug(
                    'Skipped an invalid queued tracking request: {message}',
                    ['message' => $ex->getMessage()]
                );
            } catch (\Throwable $th) {
                // Log the error to help with debugging and visibility
                $message = "There was an error while trying to process a queued tracking request.";
                $message .= "\nError:\n" . $th->getMessage() . "\nStack trace:\n" . $th->getTraceAsString();
                $configuration = new Configuration();
                if ($configuration->shouldLogFailedTrackingRequestsBody()) {
                    // Since the config should only be enabled during debugging, we should be alright using plain text
                    $message .= "\nFailed request set:\n" . json_encode($requestSet->getState());
                }

                StaticContainer::get(\Piwik\Log\LoggerInterface::class)->warning($message);

                // Wrap any throwables so that they are caught by the try/catch in Processor, which is expecting Exceptions
                throw ($th instanceof \Exception ? $th : new \Exception($th->getMessage(), $th->getCode(), $th));
            }
        }

        // so a retry of the batch does not process the requests left out again
        $requestSet->setRequests($this->trackedRequests);
        $this->requestSetsToRetry[] = $requestSet;
    }

    public function onException(RequestSet $requestSet, Exception $e)
    {
        // todo: how do we want to handle DbException or RedisException?
        $this->hasError = true;

        Common::printDebug('Got exception: ' . $e->getMessage());

        if ($this->isRetryableTransactionError($e)) {
            $this->requestSetsWithTransactionErrors[] = $requestSet;
            $this->requestSetsToRetry[] = $requestSet;
            Common::printDebug('Added requestSet that hit a transaction error to requestSetsToRetry');
            $this->restartTransaction();
            return;
        }

        if (!empty($this->trackedRequests)) {
            // keep the requests tracked before the one that failed, without the ones left out as invalid
            $requestSet->setRequests($this->trackedRequests);
            $this->requestSetsToRetry[] = $requestSet;
        }
    }

    public function hasErrors()
    {
        return $this->hasError;
    }

    /**
     * Whether a deadlock, lock wait timeout or snapshot isolation conflict happened since init().
     */
    public function hasTransactionErrors(): bool
    {
        return !empty($this->requestSetsWithTransactionErrors);
    }

    /**
     * @return RequestSet[] the request sets that hit a deadlock, lock wait timeout or snapshot isolation conflict
     *                      since init()
     */
    public function getRequestSetsWithTransactionErrors(): array
    {
        return $this->requestSetsWithTransactionErrors;
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

    /**
     * Deadlock (1213), lock wait timeout (1205) and MariaDB's snapshot isolation conflict (1020).
     */
    private function isRetryableTransactionError(Exception $e): bool
    {
        return $this->isErrNo($e, 1213) || $this->isErrNo($e, 1205) || $this->isErrNo($e, 1020);
    }

    /**
     * The mysqli adapter reads the number from the connection, which no longer has it once the failed statement was
     * freed, but the tracker's DbException carries it as its code.
     */
    private function isErrNo(Exception $e, int $errno): bool
    {
        return $this->getDb()->isErrNo($e, $errno) || ($e instanceof DbException && $e->getCode() === $errno);
    }

    /**
     * These errors can end the transaction on the server, after which PDO runs every later query in autocommit
     * mode, so the remaining request sets would be stored even though the batch is rolled back and retried.
     * Every request set processed so far is already queued for retry, so discard their work and start over.
     */
    private function restartTransaction(): void
    {
        try {
            $this->getDb()->rollBack($this->transactionId);
        } catch (Exception $e) {
            // PDO throws "There is no active transaction" once the server has rolled back.
            Common::printDebug('Rollback after a transaction error failed: ' . $e->getMessage());
        }

        $this->transactionId = $this->getDb()->beginTransaction();
    }

    protected function getDb()
    {
        return Tracker::getDatabase();
    }
}
