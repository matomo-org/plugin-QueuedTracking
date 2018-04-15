<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking\tests\Unit;

use Piwik\Config;
use Piwik\Plugins\QueuedTracking\Configuration;

/**
 * @group QueuedTracking
 * @group Plugins
 */
class ConfigurationTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Configuration
     */
    private $configuration;

    public function setUp()
    {
        parent::setUp();

        $this->configuration = new Configuration();
    }

    public function test_shouldInstallConfig()
    {
        $this->configuration->install();

        $QueuedTracking = Config::getInstance()->QueuedTracking;
        $this->assertEquals(array(
            'notify_queue_threshold_single_queue' => Configuration::DEFAULT_NOTIFY_THRESHOLD,
            'notify_queue_threshold_emails' => Configuration::$DEFAULT_NOTIFY_EMAILS
        ), $QueuedTracking);
    }

    public function test_getNotifyThreshold_shouldReturnDefaultThreshold()
    {
        $this->assertEquals(250000, $this->configuration->getNotifyThreshold());
    }

    public function test_getNotifyThreshold_shouldBePossibleToChangeValue()
    {
        Config::getInstance()->QueuedTracking = array(
            Configuration::KEY_NOTIFY_THRESHOLD => 150
        );
        $this->assertEquals(150, $this->configuration->getNotifyThreshold());
    }

    public function test_getNotifyThreshold_noConfig_shouldReturnDefault()
    {
        Config::getInstance()->QueuedTracking = array();
        $this->assertEquals(Configuration::DEFAULT_NOTIFY_THRESHOLD, $this->configuration->getNotifyThreshold());
    }


    public function test_getNotifyEmails_shouldReturnDefaultThreshold()
    {
        $this->assertEquals(array(), $this->configuration->getNotifyEmails());
    }

    public function test_getNotifyEmails_shouldBePossibleToChangeValue()
    {
        Config::getInstance()->QueuedTracking = array(
            Configuration::KEY_NOTIFY_EMAILS => ['test@matomo.org']
        );
        $this->assertEquals(['test@matomo.org'], $this->configuration->getNotifyEmails());
    }

    public function test_getNotifyEmails_noConfig_shouldReturnDefault()
    {
        Config::getInstance()->QueuedTracking = array();
        $this->assertEquals(Configuration::$DEFAULT_NOTIFY_EMAILS, $this->configuration->getNotifyEmails());
    }


}
