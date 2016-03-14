<?php

return array(

    'Piwik\Plugins\QueuedTracking\PluginSettings' => DI\decorate(function (\Piwik\Plugins\QueuedTracking\PluginSettings $settings) {
        if ($settings->redisHost->isWritableByCurrentUser()) {
            $settings->redisHost->setValue('127.0.0.1');
            $settings->redisPort->setValue(6379);
            $settings->redisPassword->setValue('');
            $settings->redisDatabase->setValue(15);
            $settings->numQueueWorkers->setValue(4);
        }

        return $settings;
    }),

);
