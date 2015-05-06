<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Access;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Plugins\QueuedTracking\Tracker\Handler;

class QueuedTracking extends \Piwik\Plugin
{
    /**
     * @see Piwik\Plugin::getListHooksRegistered
     */
    public function getListHooksRegistered()
    {
        return array(
            'Tracker.newHandler' => 'replaceHandlerIfQueueIsEnabled',
            'TestingEnvironment.addHooks' => 'configureQueueTestBackend'
        );
    }

    public function configureQueueTestBackend()
    {
        Access::doAsSuperUser(function () {
            $settings = Factory::getSettings();
            $settings->redisHost->setValue('127.0.0.1');
            $settings->redisPort->setValue(6379);
            $settings->redisPassword->setValue('');
            $settings->redisDatabase->setValue(15);
            $settings->numQueueWorkers->setValue(4);
        });
    }

    public function replaceHandlerIfQueueIsEnabled(&$handler)
    {
        $settings = Queue\Factory::getSettings();

        if ($settings->queueEnabled->getValue()) {

            $handler = new Handler();

            if ($settings->processDuringTrackingRequest->getValue()) {
                $handler->enableProcessingInTrackerMode();
            }
        }
    }

}
