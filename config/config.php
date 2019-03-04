<?php

use \Piwik\Plugins\QueuedTracking\Tracker\Handler;
use \Piwik\Plugins\QueuedTracking\Queue\Factory;

return array(
    'QueuedTrackingIsEnabled' => function () {
        $settings = Factory::getSettings();

        return $settings->queueEnabled->getValue();
    },
    'Piwik\Plugins\QueuedTracking\Tracker\Handler' => function () {
        $handler = new Handler();

        $settings = Factory::getSettings();
        if ($settings->processDuringTrackingRequest->getValue()) {
            $handler->enableProcessingInTrackerMode();
        }
        return $handler;
    }

);
