<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Cache;
use Piwik\Config;
use Piwik\Plugins\QueuedTracking\Settings\NumWorkers;
use Piwik\Settings\Setting;
use Piwik\Settings\SettingConfig;
use Piwik\Settings\Storage\Storage;
use Piwik\Settings\Storage\Backend;
use Piwik\Plugins\QueuedTracking\Queue\Factory;

/**
 * Defines Settings for QueuedTracking.
 */
class PluginSettings extends \Piwik\Settings\Plugin\PluginSettings
{
    /** @var Setting */
    public $redisHost;

    /** @var Setting */
    public $redisPort;

    /** @var Setting */
    public $redisTimeout;

    /** @var Setting */
    public $redisPassword;

    /** @var Setting */
    public $redisDatabase;

    /** @var Setting */
    public $queueEnabled;

    /** @var Setting */
    public $numRequestsToProcess;

    /** @var NumWorkers */
    public $numQueueWorkers;

    /** @var Setting */
    public $processDuringTrackingRequest;

    private $staticStorage;

    protected function init()
    {
        $this->staticStorage = new Storage(new Backend\Null('QueuedTracking'));

        $this->redisHost = $this->createRedisHostSetting();
        $this->redisPort = $this->createRedisPortSetting();
        $this->redisTimeout = $this->createRedisTimeoutSetting();
        $this->redisDatabase = $this->createRedisDatabaseSetting();
        $this->redisPassword = $this->createRedisPasswordSetting();
        $this->queueEnabled = $this->createQueueEnabledSetting();
        $this->numQueueWorkers = $this->createNumberOfQueueWorkerSetting();
        $this->numRequestsToProcess = $this->createNumRequestsToProcessSetting();
        $this->processDuringTrackingRequest = $this->createProcessInTrackingRequestSetting();
    }

    private function createRedisHostSetting()
    {
        $redisHost = new SettingConfig('redisHost', 'Redis host');
        $redisHost->type = SettingConfig::TYPE_STRING;
        $redisHost->uiControlType = SettingConfig::CONTROL_TEXT;
        $redisHost->uiControlAttributes = array('size' => 300);
        $redisHost->defaultValue = '127.0.0.1';
        $redisHost->inlineHelp = 'Remote host of the Redis server. Max 300 characters are allowed.';
        $redisHost->validate = function ($value) {
            if (strlen($value) > 300) {
                throw new \Exception('Max 300 characters allowed');
            }
        };

        return $this->makeSystemSetting($redisHost);
    }

    private function createRedisPortSetting()
    {
        $redisPort = new SettingConfig('redisPort', 'Redis port');
        $redisPort->type = SettingConfig::TYPE_INT;
        $redisPort->uiControlType = SettingConfig::CONTROL_TEXT;
        $redisPort->uiControlAttributes = array('size' => 5);
        $redisPort->defaultValue = '6379';
        $redisPort->inlineHelp = 'Port the Redis server is running on. Value should be between 1 and 65535.';
        $redisPort->validate = function ($value) {
            if ($value < 1) {
                throw new \Exception('Port has to be at least 1');
            }

            if ($value >= 65535) {
                throw new \Exception('Port should be max 65535');
            }
        };

        return $this->makeSystemSetting($redisPort);
    }

    private function createRedisTimeoutSetting()
    {
        $redisTimeout = new SettingConfig('redisTimeout', 'Redis timeout');
        $redisTimeout->type = SettingConfig::TYPE_FLOAT;
        $redisTimeout->uiControlType = SettingConfig::CONTROL_TEXT;
        $redisTimeout->uiControlAttributes = array('size' => 5);
        $redisTimeout->inlineHelp = 'Redis connection timeout in seconds. "0.0" meaning unlimited. Can be a float eg "2.5" for a connection timeout of 2.5 seconds.';
        $redisTimeout->defaultValue = 0.0;
        $redisTimeout->validate = function ($value) {

            if (!is_numeric($value)) {
                throw new \Exception('Timeout should be numeric, eg "0.1"');
            }

            if (strlen($value) > 5) {
                throw new \Exception('Max 5 characters allowed');
            }
        };
        
        $setting = $this->makeSystemSetting($redisTimeout);
        // we do not expose this one to the UI currently. That's on purpose
        $setting->setIsWritableByCurrentUser(false);

        return $setting;
    }

    private function createNumberOfQueueWorkerSetting()
    {
        $numQueueWorkers = new SettingConfig('numQueueWorkers', 'Number of queue workers');
        $numQueueWorkers->type = SettingConfig::TYPE_INT;
        $numQueueWorkers->uiControlType = SettingConfig::CONTROL_TEXT;
        $numQueueWorkers->uiControlAttributes = array('size' => 5);
        $numQueueWorkers->inlineHelp = 'Number of allowed maximum queue workers. Accepts a number between 1 and 16. Best practice is to set the number of CPUs you want to make available for queue processing. Be aware you need to make sure to start the workers manually. We recommend to not use 9-15 workers, rather use 8 or 16 as the queue might not be distributed evenly into different queues. DO NOT USE more than 1 worker if you make use the UserId feature when tracking see https://github.com/piwik/piwik/issues/7691';
        $numQueueWorkers->defaultValue = 1;
        $numQueueWorkers->validate = function ($value) {

            if (!is_numeric($value)) {
                throw new \Exception('Number of queue workers should be an integer, eg "6"');
            }

            $value = (int) $value;
            if ($value > 16 || $value < 1) {
                throw new \Exception('Only 1-16 workers allowed');
            }
        };

        $numQueueWorkers = new NumWorkers($numQueueWorkers, $this->pluginName);

        return $this->addSetting($numQueueWorkers);
    }

    private function createRedisPasswordSetting()
    {
        $redisPassword = new SettingConfig('redisPassword', 'Redis password');
        $redisPassword->type = SettingConfig::TYPE_STRING;
        $redisPassword->uiControlType = SettingConfig::CONTROL_PASSWORD;
        $redisPassword->uiControlAttributes = array('size' => 100);
        $redisPassword->inlineHelp = 'Password set on the Redis server, if any. Redis can be instructed to require a password before allowing clients to execute commands.';
        $redisPassword->defaultValue = '';
        $redisPassword->validate = function ($value) {
            if (strlen($value) > 100) {
                throw new \Exception('Max 100 characters allowed');
            }
        };

        return $this->makeSystemSetting($redisPassword);
    }

    private function createRedisDatabaseSetting()
    {
        $redisDatabase = new SettingConfig('redisDatabase', 'Redis database');
        $redisDatabase->type = SettingConfig::TYPE_INT;
        $redisDatabase->uiControlType = SettingConfig::CONTROL_TEXT;
        $redisDatabase->uiControlAttributes = array('size' => 5);
        $redisDatabase->defaultValue = 0;
        $redisDatabase->inlineHelp = 'In case you are using Redis for caching make sure to use a different database.';
        $redisDatabase->validate = function ($value) {
            if (!is_numeric($value) || false !== strpos($value, '.')) {
                throw new \Exception('The database has to be an integer');
            }

            if (strlen($value) > 5) {
                throw new \Exception('Max 5 digits allowed');
            }
        };

        return $this->makeSystemSetting($redisDatabase);
    }

    private function createQueueEnabledSetting()
    {
        $self = $this;
        $queueEnabled = new SettingConfig('queueEnabled', 'Queue enabled');
        $queueEnabled->type = SettingConfig::TYPE_BOOL;
        $queueEnabled->uiControlType = SettingConfig::CONTROL_CHECKBOX;
        $queueEnabled->inlineHelp = 'If enabled, all tracking requests will be written into a queue instead of the directly into the database. Requires a Redis server and phpredis PHP extension.';
        $queueEnabled->defaultValue = false;
        $queueEnabled->validate = function ($value) use ($self) {
            $value = (bool) $value;

            if ($value) {
                $host = $self->redisHost->getValue();
                $port = $self->redisPort->getValue();
                $timeout = $self->redisTimeout->getValue();
                $password = $self->redisPassword->getValue();

                $systemCheck = new SystemCheck();
                $systemCheck->checkRedisIsInstalled();
                $systemCheck->checkConnectionDetails($host, $port, $timeout, $password);
            }
        };

        return $this->makeSystemSetting($queueEnabled);
    }

    private function createNumRequestsToProcessSetting()
    {
        $numRequestsToProcess = new SettingConfig('numRequestsToProcess', 'Number of requests that are processed in one batch');
        $numRequestsToProcess->type  = SettingConfig::TYPE_INT;
        $numRequestsToProcess->uiControlType = SettingConfig::CONTROL_TEXT;
        $numRequestsToProcess->uiControlAttributes = array('size' => 3);
        $numRequestsToProcess->inlineHelp = 'Defines how many requests will be picked out of the queue and processed at once. Enter a number which is >= 1.';
        $numRequestsToProcess->defaultValue = 25;
        $numRequestsToProcess->validate = function ($value, $setting) {

            if (!is_numeric($value)) {
                throw new \Exception('Value should be a number');
            }

            if ((int) $value < 1) {
                throw new \Exception('Number should be 1 or higher');
            }
        };

        return $this->makeSystemSetting($numRequestsToProcess);
    }

    private function createProcessInTrackingRequestSetting()
    {
        $processDuringTrackingRequest = new SettingConfig('processDuringTrackingRequest', 'Process during tracking request');
        $processDuringTrackingRequest->type = SettingConfig::TYPE_BOOL;
        $processDuringTrackingRequest->uiControlType = SettingConfig::CONTROL_CHECKBOX;
        $processDuringTrackingRequest->inlineHelp = 'If enabled, we will process all requests within a queue during a normal tracking request once there are enough requests in the queue. This will not slow down the tracking request. If disabled, you have to setup a cronjob that executes the "./console queuedtracking:process" console command eg every minute to process the queue.';
        $processDuringTrackingRequest->defaultValue = true;

        return $this->makeSystemSetting($processDuringTrackingRequest);
    }

    public function save()
    {
        parent::save();

        $oldNumWorkers = $this->numQueueWorkers->getOldValue();
        $newNumWorkers = $this->numQueueWorkers->getValue();

        if ($newNumWorkers && $oldNumWorkers) {
            try {
                $manager = Factory::makeQueueManager(Factory::makeBackend());
                $manager->setNumberOfAvailableQueues($newNumWorkers);
                $manager->moveSomeQueuesIfNeeded($newNumWorkers, $oldNumWorkers);
            } catch (\Exception $e) {
                // it is ok if this fails. then it is most likely not enabled etc.
            }
        }
    }

}
