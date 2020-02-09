<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue;

use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Lock;
use Piwik\Plugins\QueuedTracking\Queue\Processor;

/**
 * @group QueuedTracking
 * @group Queue
 * @group ProcessorTest
 * @group Tracker
 * @group Redis
 */
class LockTest extends IntegrationTestCase
{

    protected $testRequiresRedis = false;

    /**
     * @var Lock
     */
    public $lock;

    public function setUp(): void
    {
        parent::setUp();

        $redis = $this->createMySQLBackend();
        $this->lock = $this->createLock($redis);
    }

    public function tearDown(): void
    {
        $this->clearBackend();
        parent::tearDown();
    }

    public function test_acquireLock_ShouldLockInCaseItIsNotLockedYet()
    {
        $this->assertTrue($this->lock->acquireLock(0));
        $this->assertFalse($this->lock->acquireLock(0));

        $this->lock->unlock();

        $this->assertTrue($this->lock->acquireLock(0));
        $this->assertFalse($this->lock->acquireLock(0));
    }

    public function test_acquireLock_ShouldBeAbleToLockMany()
    {
        $this->assertTrue($this->lock->acquireLock(0));
        $this->assertFalse($this->lock->acquireLock(0));
        $this->assertTrue($this->lock->acquireLock(1));
        $this->assertTrue($this->lock->acquireLock(2));
        $this->assertFalse($this->lock->acquireLock(1));
    }

    public function test_isLocked_ShouldDetermineWhetherAQueueIsLocked()
    {
        $this->assertFalse($this->lock->isLocked());
        $this->lock->acquireLock(0);

        $this->assertTrue($this->lock->isLocked());

        $this->lock->unlock();

        $this->assertFalse($this->lock->isLocked());
    }

    public function test_unlock_OnlyUnlocksTheLastOne()
    {
        $this->assertTrue($this->lock->acquireLock(0));
        $this->assertTrue($this->lock->acquireLock(1));
        $this->assertTrue($this->lock->acquireLock(2));

        $this->lock->unlock();

        $this->assertFalse($this->lock->acquireLock(0));
        $this->assertFalse($this->lock->acquireLock(1));
        $this->assertTrue($this->lock->acquireLock(2));
    }

    public function test_expireLock_ShouldReturnTrueOnSuccess()
    {
        $this->lock->acquireLock(0);
        $this->assertTrue($this->lock->expireLock(2));
    }

    public function test_expireLock_ShouldReturnFalseIfNoTimeoutGiven()
    {
        $this->lock->acquireLock(0);
        $this->assertFalse($this->lock->expireLock(0));
    }

    public function test_expireLock_ShouldReturnFalseIfNotLocked()
    {
        $this->assertFalse($this->lock->expireLock(2));
    }

    public function test_getNumberOfAcquiredLocks_shouldReturnNumberOfLocks()
    {
        $this->assertNumberOfLocksEquals(0);

        $this->lock->acquireLock(0);
        $this->assertNumberOfLocksEquals(1);

        $this->lock->acquireLock(4);
        $this->lock->acquireLock(5);
        $this->assertNumberOfLocksEquals(3);

        $this->lock->unlock();
        $this->assertNumberOfLocksEquals(2);
    }

    public function test_getAllAcquiredLockKeys_shouldReturnUsedKeysThatAreLocked()
    {
        $this->assertSame(array(), $this->lock->getAllAcquiredLockKeys());

        $this->lock->acquireLock(0);
        $this->assertSame(array('QueuedTrackingLock0'), $this->lock->getAllAcquiredLockKeys());

        $this->lock->acquireLock(4);
        $this->lock->acquireLock(5);

        $locks = $this->lock->getAllAcquiredLockKeys();
        sort($locks);
        $this->assertSame(array('QueuedTrackingLock0', 'QueuedTrackingLock4', 'QueuedTrackingLock5'), $locks);
    }

    private function assertNumberOfLocksEquals($numExpectedLocks)
    {
        $this->assertSame($numExpectedLocks, $this->lock->getNumberOfAcquiredLocks());
    }

    private function createLock($redis)
    {
        return new Lock($redis);
    }

}
