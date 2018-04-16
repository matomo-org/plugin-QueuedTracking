<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Config;

class Configuration
{
    public static $DEFAULT_NOTIFY_EMAILS = [];
    const DEFAULT_NOTIFY_THRESHOLD = 250000;
    const KEY_NOTIFY_EMAILS = 'notify_queue_threshold_emails';
    const KEY_NOTIFY_THRESHOLD = 'notify_queue_threshold_single_queue';

    public function install()
    {
        $config = $this->getConfig();

        if (empty($config->QueuedTracking)) {
            $config->QueuedTracking = array();
        }
        $reports = $config->QueuedTracking;

        // we make sure to set a value only if none has been configured yet, eg in common config.
        if (empty($reports[self::KEY_NOTIFY_THRESHOLD])) {
            $reports[self::KEY_NOTIFY_THRESHOLD] = self::DEFAULT_NOTIFY_THRESHOLD;
        }
        if (empty($reports[self::KEY_NOTIFY_EMAILS])) {
            $reports[self::KEY_NOTIFY_EMAILS] = self::$DEFAULT_NOTIFY_EMAILS;
        }
        $config->QueuedTracking = $reports;

        $config->forceSave();
    }

    public function uninstall()
    {
        $config = $this->getConfig();
        $config->QueuedTracking = array();
        $config->forceSave();
    }

    /**
     * @return int
     */
    public function getNotifyThreshold()
    {
        $value = $this->getConfigValue(self::KEY_NOTIFY_THRESHOLD, self::DEFAULT_NOTIFY_THRESHOLD);

        if ($value === false || $value === '' || $value === null) {
            $value = self::DEFAULT_NOTIFY_THRESHOLD;
        }

        return (int) $value;
    }

    /**
     * @return array
     */
    public function getNotifyEmails()
    {
        $value = $this->getConfigValue(self::KEY_NOTIFY_EMAILS, self::$DEFAULT_NOTIFY_EMAILS);

        if (empty($value)) {
            $value = self::$DEFAULT_NOTIFY_EMAILS;
        }
        if (!is_array($value)) {
            $value = array($value);
        }

        return $value;
    }

    private function getConfig()
    {
        return Config::getInstance();
    }

    private function getConfigValue($name, $default)
    {
        $config = $this->getConfig();
        $attribution = $config->QueuedTracking;
        if (isset($attribution[$name])) {
            return $attribution[$name];
        }
        return $default;
    }
}
