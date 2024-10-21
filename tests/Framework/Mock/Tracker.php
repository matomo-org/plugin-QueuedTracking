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
    public function trackRequest(Request $request)
    {
        $allParams = $request->getRawParams();
        if (!empty($allParams['forceThrow'])) {
            throw new ForcedException("forced exception");
        }

        return parent::trackRequest($request);
    }
}
