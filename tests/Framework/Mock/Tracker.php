<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Framework\Mock;

use Piwik\Tracker\Db\DbException;
use Piwik\Tracker\Request;

class Tracker extends \Piwik\Tests\Framework\Mock\Tracker
{
    public $numDeadlocks = 0;

    /**
     * Counted by TestProcessor over the tracker's lifetime, so in a test that processes a single batch a pass's index is
     * one less: 0 for its first processing, n for its nth retry.
     */
    public $numPasses = 0;

    /**
     * Keyed by the request's params, as a request is restored from the queue as a new object. Requests with identical
     * params therefore share a count, so give each one a param of its own when a test needs them counted apart.
     * @var int[]
     */
    private $numDeadlocksPerRequest = [];

    public function trackRequest(Request $request)
    {
        $allParams = $request->getRawParams();
        $key = json_encode($allParams);
        $numOwnDeadlocks = $this->numDeadlocksPerRequest[$key] ?? 0;

        if (!empty($allParams['forceThrow']) || (!empty($allParams['forceThrowAfterDeadlock']) && $numOwnDeadlocks)) {
            throw new ForcedException("forced exception");
        }

        if (
            !empty($allParams['forceDeadlock'])
            || (!empty($allParams['forceDeadlockOnce']) && !$numOwnDeadlocks)
            || (!empty($allParams['forceDeadlockUntil']) && $numOwnDeadlocks < $allParams['forceDeadlockUntil'])
            || (!empty($allParams['forceDeadlockOnceAfterDeadlocks']) && !$numOwnDeadlocks
                && $this->numDeadlocks >= $allParams['forceDeadlockOnceAfterDeadlocks'])
            || (isset($allParams['forceDeadlockInPasses'])
                && in_array((string) ($this->numPasses - 1), explode(',', $allParams['forceDeadlockInPasses']), true))
        ) {
            $this->numDeadlocks++;
            $this->numDeadlocksPerRequest[$key] = $numOwnDeadlocks + 1;
            throw new DbException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 1213);
        }

        return parent::trackRequest($request);
    }
}
