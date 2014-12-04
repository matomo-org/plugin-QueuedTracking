<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;

use Piwik\Plugins\QueuedTracking\SystemCheck;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group QueuedTracking
 * @group SystemCheckTest
 * @group Plugins
 */
class SystemCheckTest extends IntegrationTestCase
{
    /**
     * @var SystemCheck
     */
    private $systemCheck;

    public function setUp()
    {
        parent::setUp();

        $this->systemCheck = new SystemCheck();
    }

    public function test_checkIsInstalled_shouldNotFailOnSystemsWherePhpRedisIsAvailable()
    {
        $this->systemCheck->checkRedisIsInstalled();

        $this->assertTrue(true);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Connection to Redis failed. Please verify Redis host and port
     */
    public function test_checkConnectionDetails_shouldFailIfServerIsWrong()
    {
        $this->systemCheck->checkConnectionDetails('192.168.123.234', 6379, 0.2, null);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Connection to Redis failed. Please verify Redis host and port
     */
    public function test_checkConnectionDetails_shouldFailIfPortIsWrong()
    {
        $this->systemCheck->checkConnectionDetails('127.0.0.1', 6370, 0.2, null);
    }

    public function test_checkConnectionDetails_shouldNotFailIfConnectionDataIsCorrect()
    {
        $this->systemCheck->checkConnectionDetails('127.0.0.1', 6379, 0.2, null);
        $this->assertTrue(true);
    }

}
