<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue;

class Lock extends \Piwik\Concurrency\Lock
{
    public const LOCK_KEY_START = 'QueuedTrackingLock';

    public function __construct(Backend $backend)
    {
        parent::__construct($backend, self::LOCK_KEY_START);
    }
}
