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
use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;

class Lock
{
    /**
     * @var Redis
     */
    private $backend;

    private $lockKeyStart = 'QueuedTrackingLock';

    private $lockKey   = null;
    private $lockValue = null;

    public function __construct(Backend $backend)
    {
        $this->backend = $backend;
        $this->lockKey = $this->lockKeyStart;
    }

    public function getNumberOfAcquiredLocks()
    {
        return count($this->getAllAcquiredLockKeys());
    }

    public function getAllAcquiredLockKeys()
    {
        return $this->backend->getKeysMatchingPattern($this->lockKeyStart . '*');
    }

    public function acquireLock($id)
    {
        $this->lockKey = $this->lockKeyStart . $id;

        $lockValue = substr(Common::generateUniqId(), 0, 12);
        $locked    = $this->backend->setIfNotExists($this->lockKey, $lockValue, $ttlInSeconds = 60);

        if ($locked) {
            $this->lockValue = $lockValue;
        }

        return $locked;
    }

    public function isLocked()
    {
        if (!$this->lockValue) {
            return false;
        }

        return $this->lockValue === $this->backend->get($this->lockKey);
    }

    public function unlock()
    {
        if ($this->lockValue) {
            $this->backend->deleteIfKeyHasValue($this->lockKey, $this->lockValue);
            $this->lockValue = null;
        }
    }

    public function expireLock($ttlInSeconds)
    {
        if ($ttlInSeconds > 0 && $this->lockValue) {
            return $this->backend->expireIfKeyHasValue($this->lockKey, $this->lockValue, $ttlInSeconds);
        }

        return false;
    }
}
