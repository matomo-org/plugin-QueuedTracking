<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;

use Piwik\Access;
use Piwik\Config;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
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

    public function setUp(): void
    {
        parent::setUp();

        $this->systemCheck = new SystemCheck();
    }

    public function test_checkIsInstalled_shouldNotFailOnSystemsWherePhpRedisIsAvailable()
    {
        $this->systemCheck->checkRedisIsInstalled();

        $this->assertTrue(true);
    }

    public function test_checkConnectionDetails_shouldFailIfServerIsWrong()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Connection to Redis failed. Please verify Redis host and port');

        $backend = $this->makeBackend('192.168.123.234', 6379, 0.2, null);
        $this->systemCheck->checkConnectionDetails($backend);
    }

    public function test_checkConnectionDetails_shouldFailIfPortIsWrong()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Connection to Redis failed. Please verify Redis host and port');

        $backend = $this->makeBackend('127.0.0.1', 6370, 0.2, null);
        $this->systemCheck->checkConnectionDetails($backend);
    }

    public function test_checkConnectionDetails_shouldNotFailIfConnectionDataIsCorrect()
    {
        $backend = $this->makeBackend('127.0.0.1', 6379, 0.2, null);
        $this->systemCheck->checkConnectionDetails($backend);
        $this->assertTrue(true);
    }

    private function makeBackend($host, $port, $timeout, $password)
    {
        $settings = Factory::getSettings();
        $settings->redisHost->setValue($host);
        $settings->redisPort->setValue($port);
        $settings->redisTimeout->setIsWritableByCurrentUser(true);
        $settings->redisTimeout->setValue($timeout);
        $settings->redisPassword->setValue($password);

        return Factory::makeBackendFromSettings($settings);
    }

}
