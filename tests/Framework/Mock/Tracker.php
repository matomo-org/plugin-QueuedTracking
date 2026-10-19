<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Framework\Mock;

use Piwik\Tracker\Request;

class Tracker extends \Piwik\Tests\Framework\Mock\Tracker
{
    public $numDeadlocks = 0;

    /**
     * Keyed by the request's params, as a request is restored from the queue as a new object.
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
        ) {
            $this->numDeadlocks++;
            $this->numDeadlocksPerRequest[$key] = $numOwnDeadlocks + 1;
            throw new ForcedException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        }

        return parent::trackRequest($request);
    }
}
