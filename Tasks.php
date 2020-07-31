<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Common;
use Piwik\Config;
use Piwik\Db;
use Piwik\Mail;
use Piwik\Plugins\QueuedTracking\Queue\Backend\MySQL;
use Piwik\SettingsPiwik;

class Tasks extends \Piwik\Plugin\Tasks
{
    /**
     * @var Configuration
     */
    private $config;

    public function __construct(Configuration $configuration)
    {
        $this->config = $configuration;
    }

    public function schedule()
    {
        $this->daily('optimizeQueueTable', null, self::LOWEST_PRIORITY);
        $this->hourly('notifyQueueSize', null, self::LOWEST_PRIORITY);
    }

    /**
     * run eg using ./console core:run-scheduled-tasks "Piwik\Plugins\QueuedTracking\Tasks.notifyQueueSize"
     */
    public function notifyQueueSize()
    {
        $settings = Queue\Factory::getSettings();

        if (!$settings->queueEnabled->getValue()) {
            // not needed to check anything
            return;
        }

        $emailsToNotify = $this->config->getNotifyEmails();
        $threshold = $this->config->getNotifyThreshold();

        if (empty($emailsToNotify) || empty($threshold) || $threshold <= 0) {
            // nobody to notify or no threshold defined
            return;
        }

        $backend      = Queue\Factory::makeBackend();
        $queueManager = Queue\Factory::makeQueueManager($backend);

        $larger = "";
        $smaller = "";

        foreach ($queueManager->getAllQueues() as $queue) {
            $size = $queue->getNumberOfRequestSetsInQueue();
            $entriesMessage = sprintf("Queue ID %s has %s entries.<br />", $queue->getId(), $size);
            if ($size >= $threshold) {
                $larger .= $entriesMessage;
            } else {
                $smaller .= $entriesMessage;
            }
        }

        if (!empty($larger)) {
            $message = sprintf("This is a notification that the threshold %s for a single queue has been reached.<br /><br />The following queue sizes are greater than the threshold: <br />%s", $threshold, $larger);

            if (!empty($smaller)) {
                $message .= sprintf("<br /><br />The remaining queue sizes, which are below the threshold, are listed below: <br />%s", $smaller);
            }

            $message = $message . "<br /><br />Sent from " . SettingsPiwik::getPiwikUrl();
            $mail = new Mail();
            $mail->setDefaultFromPiwik();
            foreach ($emailsToNotify as $emailToNotify) {
                $mail->addTo($emailToNotify);
            }
            $mail->setSubject('Queued Tracking - queue size has reached your threshold');
            $mail->setWrappedHtmlBody($message);
            $mail->send();
        }
    }

    /**
     * run eg using ./console core:run-scheduled-tasks "Piwik\Plugins\QueuedTracking\Tasks.optimizeQueueTable"
     */
    public function optimizeQueueTable()
    {
        $settings = Queue\Factory::getSettings();
        if ($settings->isMysqlBackend() && $settings->queueEnabled->getValue()) {

            $db = Db::get();
            $prefix = Common::prefixTable(MySQL::QUEUED_TRACKING_TABLE_PREFIX);
            $tables = $db->fetchCol("SHOW TABLES LIKE '" . $prefix . "%'");

            $force = Db::isOptimizeInnoDBSupported();
            // if supported, then we want to force it, as it is quite important to execute this
            Db::optimizeTables($tables, $force);
        }
    }

}