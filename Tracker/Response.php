<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Tracker;

use Piwik\Common;
use Piwik\Tracker\Response as TrackerResponse;

class Response extends TrackerResponse
{
    public function sendResponseToBrowserDirectly()
    {
        while (ob_get_level() > 1) {
            ob_end_flush();
        }

        Common::sendHeader("Connection: close\r\n", true);
        Common::sendHeader("Content-Encoding: none\r\n", true);
        Common::sendHeader('Content-Length: ' . ob_get_length(), true);
        ob_end_flush();
        flush();
    }
}
