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
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Plugins\QueuedTracking\Settings\NumWorkers;
use Piwik\Settings\Storage\StaticStorage;
use Piwik\Settings\SystemSetting;

/**
 * Defines Settings for QueuedTracking.
 */
class Settings extends \Piwik\Plugin\Settings
{
    /** @var SystemSetting */
    public $redisHost;

    /** @var SystemSetting */
    public $redisPort;

    /** @var SystemSetting */
    public $redisTimeout;

    /** @var SystemSetting */
    public $redisPassword;

    /** @var SystemSetting */
    public $redisDatabase;

    /** @var SystemSetting */
    public $queueEnabled;

    /** @var SystemSetting */
    public $numRequestsToProcess;

    /** @var SystemSetting */
    public $numQueueWorkers;

    /** @var SystemSetting */
    public $processDuringTrackingRequest;

    private $staticStorage;

    protected function init()
    {
        $this->staticStorage = new StaticStorage('QueuedTracking');

        $this->createRedisHostSetting();
        $this->createRedisPortSetting();
        $this->createRedisTimeoutSetting();
        $this->createRedisDatabaseSetting();
        $this->createRedisPasswordSetting();
        $this->createQueueEnabledSetting();
        $this->createNumberOfQueueWorkerSetting();
        $this->createNumRequestsToProcessSetting();
        $this->createProcessInTrackingRequestSetting();
    }

    public function isUsingSentinelBackend()
    {
        $queuedTracking = $this->getQueuedTrackingConfig();
        return !empty($queuedTracking['backend']) && $queuedTracking['backend'] === 'sentinel';
    }

    public function getSentinelMasterName()
    {
        $queuedTracking = $this->getQueuedTrackingConfig();
        if (!empty($queuedTracking['sentinel_master_name'])) {
            return $queuedTracking['sentinel_master_name'];
        }
    }

    private function getQueuedTrackingConfig()
    {
        return Config::getInstance()->QueuedTracking;
    }

    private function createRedisHostSetting()
    {
        $this->redisHost = new SystemSetting('redisHost', 'Redis host');
        $this->redisHost->readableByCurrentUser = true;
        $this->redisHost->type = static::TYPE_STRING;
        $this->redisHost->uiControlType = static::CONTROL_TEXT;
        $this->redisHost->uiControlAttributes = array('size' => 300);
        $this->redisHost->defaultValue = '127.0.0.1';
        $this->redisHost->inlineHelp = 'Remote host of the Redis server. Max 300 characters are allowed.';
        $this->redisHost->validate = function ($value) {
            if (strlen($value) > 300) {
                throw new \Exception('Max 300 characters allowed');
            }
        };

        $this->addSetting($this->redisHost);
    }

    private function createRedisPortSetting()
    {
        $this->redisPort = new SystemSetting('redisPort', 'Redis port');
        $this->redisPort->readableByCurrentUser = true;
        $this->redisPort->type = static::TYPE_INT;
        $this->redisPort->uiControlType = static::CONTROL_TEXT;
        $this->redisPort->uiControlAttributes = array('size' => 5);
        $this->redisPort->defaultValue = '6379';
        $this->redisPort->inlineHelp = 'Port the Redis server is running on. Value should be between 1 and 65535.';
        $this->redisPort->validate = function ($value) {
            if ($value < 1) {
                throw new \Exception('Port has to be at least 1');
            }

            if ($value >= 65535) {
                throw new \Exception('Port should be max 65535');
            }
        };

        $this->addSetting($this->redisPort);
    }

    private function createRedisTimeoutSetting()
    {
        $this->redisTimeout = new SystemSetting('redisTimeout', 'Redis timeout');
        $this->redisTimeout->readableByCurrentUser = true;
        $this->redisTimeout->type = static::TYPE_FLOAT;
        $this->redisTimeout->uiControlType = static::CONTROL_TEXT;
        $this->redisTimeout->uiControlAttributes = array('size' => 5);
        $this->redisTimeout->inlineHelp = 'Redis connection timeout in seconds. "0.0" meaning unlimited. Can be a float eg "2.5" for a connection timeout of 2.5 seconds.';
        $this->redisTimeout->defaultValue = 0.0;
        $this->redisTimeout->validate = function ($value) {

            if (!is_numeric($value)) {
                throw new \Exception('Timeout should be numeric, eg "0.1"');
            }

            if (strlen($value) > 5) {
                throw new \Exception('Max 5 characters allowed');
            }
        };

        // we do not expose this one to the UI currently. That's on purpose
        $this->redisTimeout->setStorage($this->staticStorage);
    }

    private function createNumberOfQueueWorkerSetting()
    {
        $this->numQueueWorkers = new NumWorkers('numQueueWorkers', 'Number of queue workers');
        $this->numQueueWorkers->readableByCurrentUser = true;
        $this->numQueueWorkers->type = static::TYPE_INT;
        $this->numQueueWorkers->uiControlType = static::CONTROL_TEXT;
        $this->numQueueWorkers->uiControlAttributes = array('size' => 5);
        $this->numQueueWorkers->inlineHelp = 'Number of allowed maximum queue workers. Accepts a number between 1 and 16. Best practice is to set the number of CPUs you want to make available for queue processing. Be aware you need to make sure to start the workers manually. We recommend to not use 9-15 workers, rather use 8 or 16 as the queue might not be distributed evenly into different queues. DO NOT USE more than 1 worker if you make use the UserId feature when tracking see https://github.com/piwik/piwik/issues/7691';
        $this->numQueueWorkers->defaultValue = 1;
        $this->numQueueWorkers->validate = function ($value) {

            if (!is_numeric($value)) {
                throw new \Exception('Number of queue workers should be an integer, eg "6"');
            }

            $value = (int) $value;
            if ($value > 16 || $value < 1) {
                throw new \Exception('Only 1-16 workers allowed');
            }
        };

        $this->addSetting($this->numQueueWorkers);
    }

    private function createRedisPasswordSetting()
    {
        $this->redisPassword = new SystemSetting('redisPassword', 'Redis password');
        $this->redisPassword->readableByCurrentUser = true;
        $this->redisPassword->type = static::TYPE_STRING;
        $this->redisPassword->uiControlType = static::CONTROL_PASSWORD;
        $this->redisPassword->uiControlAttributes = array('size' => 100);
        $this->redisPassword->inlineHelp = 'Password set on the Redis server, if any. Redis can be instructed to require a password before allowing clients to execute commands.';
        $this->redisPassword->defaultValue = '';
        $this->redisPassword->validate = function ($value) {
            if (strlen($value) > 100) {
                throw new \Exception('Max 100 characters allowed');
            }
        };

        $this->addSetting($this->redisPassword);
    }

    private function createRedisDatabaseSetting()
    {
        $this->redisDatabase = new SystemSetting('redisDatabase', 'Redis database');
        $this->redisDatabase->readableByCurrentUser = true;
        $this->redisDatabase->type = static::TYPE_INT;
        $this->redisDatabase->uiControlType = static::CONTROL_TEXT;
        $this->redisDatabase->uiControlAttributes = array('size' => 5);
        $this->redisDatabase->defaultValue = 0;
        $this->redisDatabase->inlineHelp = 'In case you are using Redis for caching make sure to use a different database.';
        $this->redisDatabase->validate = function ($value) {
            if (!is_numeric($value) || false !== strpos($value, '.')) {
                throw new \Exception('The database has to be an integer');
            }

            if (strlen($value) > 5) {
                throw new \Exception('Max 5 digits allowed');
            }
        };

        $this->addSetting($this->redisDatabase);
    }

    private function createQueueEnabledSetting()
    {
        $self = $this;
        $this->queueEnabled = new SystemSetting('queueEnabled', 'Queue enabled');
        $this->queueEnabled->readableByCurrentUser = true;
        $this->queueEnabled->type = static::TYPE_BOOL;
        $this->queueEnabled->uiControlType = static::CONTROL_CHECKBOX;
        $this->queueEnabled->inlineHelp = 'If enabled, all tracking requests will be written into a queue instead of the directly into the database. Requires a Redis server and phpredis PHP extension.';
        $this->queueEnabled->defaultValue = false;
        $this->queueEnabled->validate = function ($value) use ($self) {
            $value = (bool) $value;

            if ($value) {
                $systemCheck = new SystemCheck();

                if (!$self->isUsingSentinelBackend()) {
                    $systemCheck->checkRedisIsInstalled();
                }

                $backend = Factory::makeBackendFromSettings($self);
                $systemCheck->checkConnectionDetails($backend);

            }
        };

        $this->addSetting($this->queueEnabled);
    }

    private function createNumRequestsToProcessSetting()
    {
        $this->numRequestsToProcess = new SystemSetting('numRequestsToProcess', 'Number of requests that are processed in one batch');
        $this->numRequestsToProcess->readableByCurrentUser = true;
        $this->numRequestsToProcess->type  = static::TYPE_INT;
        $this->numRequestsToProcess->uiControlType = static::CONTROL_TEXT;
        $this->numRequestsToProcess->uiControlAttributes = array('size' => 3);
        $this->numRequestsToProcess->inlineHelp = 'Defines how many requests will be picked out of the queue and processed at once. Enter a number which is >= 1.';
        $this->numRequestsToProcess->defaultValue = 25;
        $this->numRequestsToProcess->validate = function ($value, $setting) {

            if (!is_numeric($value)) {
                throw new \Exception('Value should be a number');
            }

            if ((int) $value < 1) {
                throw new \Exception('Number should be 1 or higher');
            }
        };

        $this->addSetting($this->numRequestsToProcess);
    }

    private function createProcessInTrackingRequestSetting()
    {
        $this->processDuringTrackingRequest = new SystemSetting('processDuringTrackingRequest', 'Process during tracking request');
        $this->processDuringTrackingRequest->readableByCurrentUser = true;
        $this->processDuringTrackingRequest->type = static::TYPE_BOOL;
        $this->processDuringTrackingRequest->uiControlType = static::CONTROL_CHECKBOX;
        $this->processDuringTrackingRequest->inlineHelp = 'If enabled, we will process all requests within a queue during a normal tracking request once there are enough requests in the queue. This will not slow down the tracking request. If disabled, you have to setup a cronjob that executes the "./console queuedtracking:process" console command eg every minute to process the queue.';
        $this->processDuringTrackingRequest->defaultValue = true;

        $this->addSetting($this->processDuringTrackingRequest);
    }

}
