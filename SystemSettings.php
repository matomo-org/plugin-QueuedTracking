<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Plugins\QueuedTracking\Settings\NumWorkers;
use Piwik\Settings\Setting;
use Piwik\Settings\FieldConfig;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Piwik;
use Exception;
use Piwik\Validators\CharacterLength;
use Piwik\Validators\NumberRange;

/**
 * Defines Settings for QueuedTracking.
 */
class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    /** @var Setting */
    public $backend;

    /** @var Setting */
    public $redisHost;

    /** @var Setting */
    public $redisPort;

    /** @var Setting */
    public $redisTimeout;

    /** @var Setting */
    public $redisDatabase;

    /** @var Setting */
    public $redisPassword;

    /** @var Setting */
    public $queueEnabled;

    /** @var NumWorkers */
    public $numQueueWorkers;

    /** @var Setting */
    public $processDuringTrackingRequest;

    /** @var Setting */
    public $numRequestsToProcess;

    /** @var Setting */
    public $useWhatRedisBackendType;

    /** @var Setting */
    public $sentinelMasterName;

    /** @var Array */
    protected $availableRedisBackendType = array(1=>"Stand Alone", 2=>"Sentinel", 3=>"Cluster");    

    protected function assignValueIsIntValidator (FieldConfig $field) {
        $field->validate = function ($value) {
            if ((is_string($value) && !ctype_digit($value)) || (!is_string($value) && !is_int($value))) {
                throw new \Exception(Piwik::translate('QueuedTracking_ExceptionValueIsNotInt'));
            }
        };
    }

    protected function init()
    {
        $this->backend = $this->createBackendSetting();
        $this->useWhatRedisBackendType = $this->createUseWhatRedisBackendType();
        $this->sentinelMasterName = $this->createSetSentinelMasterName();
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

    public function isUsingSentinelBackend()
    {
        return $this->useWhatRedisBackendType->getValue() === 2;
    }

    public function isUsingClusterBackend()
    {
        return $this->useWhatRedisBackendType->getValue() === 3;
    }

    public function getRedisType($string=true)
    {
        return $this->availableRedisBackendType[(int)$this->useWhatRedisBackendType->getValue()];
    }

    public function getSentinelMasterName()
    {
        return $this->sentinelMasterName->getValue();
    }

    public function isUsingUnixSocket()
    {
        return substr($this->redisHost->getValue(), 0, 1) === '/';
    }

    private function createRedisHostSetting()
    {
        $self = $this;

        return $this->makeSetting('redisHost', $default = '127.0.0.1', FieldConfig::TYPE_STRING, function (FieldConfig $field) use ($self) {
            $field->title = Piwik::translate('QueuedTracking_RedisHostFieldTitle');
            $field->condition = 'backend=="redis"';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 500);
            $field->inlineHelp = Piwik::translate('QueuedTracking_RedisHostFieldHelp') . '</br></br>'
                . Piwik::translate('QueuedTracking_RedisHostFieldHelpExtended') . '</br>';

            if ($self->isUsingSentinelBackend() or $self->isUsingClusterBackend()) {
                $field->inlineHelp .= '</br>' . Piwik::translate('QueuedTracking_RedisHostFieldHelpExtendedSentinel') . '</br>';
            }

            $field->validate = function ($value) use ($self) {
                $self->checkMultipleServersOnlyConfiguredWhenSentinelIsEnabled($value);

                (new CharacterLength(1, 500))->validate($value);
            };

            $field->transform = function ($value) use ($self) {
                $hosts = $self->convertCommaSeparatedValueToArray($value);

                return implode(',', $hosts);
            };
        });
    }

    private function createRedisPortSetting()
    {
        $self = $this;

        $default = '6379';
        if ($this->isUsingSentinelBackend()) {
            $default = '26379';
        }

        return $this->makeSetting('redisPort', $default, FieldConfig::TYPE_STRING, function (FieldConfig $field) use ($self) {
            $field->title = Piwik::translate('QueuedTracking_RedisPortFieldTitle');
            $field->condition = 'backend=="redis"';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 100);
            $field->inlineHelp = Piwik::translate('QueuedTracking_RedisPortFieldHelp') . '</br>';

            if ($self->isUsingSentinelBackend() or $self->isUsingClusterBackend()) {
                $field->inlineHelp .= '</br>' . Piwik::translate('QueuedTracking_RedisHostFieldHelpExtendedSentinel') . '</br>';
            }

            $field->validate = function ($value) use ($self) {
                $self->checkMultipleServersOnlyConfiguredWhenSentinelIsEnabled($value);

                if ($self->isUsingSentinelBackend() or $self->isUsingClusterBackend()) {
                    $ports = explode(',', $value);
                    foreach ($ports as $port) {
                        (new NumberRange(0, 65535))->validate(trim($port));
                    }
                } else {
                    (new NumberRange(0, 65535))->validate($value);
                }
            };

            $field->transform = function ($value) use ($self) {
                $ports = $self->convertCommaSeparatedValueToArray($value);
                $ports = array_map('intval', $ports);

                return implode(',', $ports);
            };
        });
    }

    private function createRedisTimeoutSetting()
    {
        $setting = $this->makeSetting('redisTimeout', $default = 0.0, FieldConfig::TYPE_FLOAT, function (FieldConfig $field) {
            $field->title = Piwik::translate('QueuedTracking_RedisTimeoutFieldTitle');
            $field->condition = 'backend=="redis"';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 5);
            $field->inlineHelp = Piwik::translate('QueuedTracking_RedisTimeoutFieldTitle') . '</br>';
            $field->validators[] = new NumberRange();
            $field->validators[] = new CharacterLength(1, 5);
        });

        // we do not expose this one to the UI currently. That's on purpose
        $setting->setIsWritableByCurrentUser(false);

        return $setting;
    }

    private function createNumberOfQueueWorkerSetting()
    {
        $numQueueWorkers = new NumWorkers('numQueueWorkers', $default = 1, FieldConfig::TYPE_INT, $this->pluginName);
        $numQueueWorkers->setConfigureCallback(function (FieldConfig $field) {
            $field->title = Piwik::translate('QueuedTracking_NumberOfQueueWorkersFieldTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 5);
            $field->inlineHelp = Piwik::translate('QueuedTracking_NumberOfQueueWorkersFieldHelp') . '</br>';
            $this->assignValueIsIntValidator($field);
            $field->validators[] = new NumberRange(1, 4096);
        });

        $this->addSetting($numQueueWorkers);

        return $numQueueWorkers;
    }

    private function createRedisPasswordSetting()
    {
        return $this->makeSetting('redisPassword', $default = '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('QueuedTracking_RedisPasswordFieldTitle');
            $field->condition = 'backend=="redis"';
            $field->uiControl = FieldConfig::UI_CONTROL_PASSWORD;
            $field->uiControlAttributes = array('size' => 128);
            $field->inlineHelp = Piwik::translate('QueuedTracking_RedisPasswordFieldHelp') . '</br>';
            $field->validators[] = new CharacterLength(null, 128);
        });
    }

    private function createRedisDatabaseSetting()
    {
        return $this->makeSetting('redisDatabase', $default = 0, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = Piwik::translate('QueuedTracking_RedisDatabaseFieldTitle');
            $field->condition = 'backend=="redis"';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 5);
            $field->inlineHelp = Piwik::translate('QueuedTracking_RedisDatabaseFieldHelp') . '</br>';
            $field->validators[] = new NumberRange();
            $field->validators[] = new CharacterLength(1, 5);
            $this->assignValueIsIntValidator($field);
        });
    }

    private function createQueueEnabledSetting()
    {
        $self = $this;

        return $this->makeSetting('queueEnabled', $default = false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) use ($self) {
            $field->title = Piwik::translate('QueuedTracking_QueueEnabledFieldTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->inlineHelp = Piwik::translate('QueuedTracking_QueueEnabledFieldHelp') . '</br>';
            $field->validate = function ($value) use ($self) {
                $value = (bool) $value;

                if ($value && $self->isRedisBackend()) {
                    $self->checkMatchHostsAndPorts();

                    $systemCheck = new SystemCheck();

                    if ($self->isRedisBackend() && !$self->isUsingSentinelBackend()) {
                        $systemCheck->checkRedisIsInstalled();
                    }
                    $backend = Factory::makeBackendFromSettings($self);

                    $systemCheck->checkConnectionDetails($backend);
                }
            };
        });
    }

    private function createNumRequestsToProcessSetting()
    {
        return $this->makeSetting('numRequestsToProcess', $default = 25, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = Piwik::translate('QueuedTracking_NumRequestsToProcessFieldTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 3);
            $field->inlineHelp = Piwik::translate('QueuedTracking_NumRequestsToProcessFieldHelp') . '</br>';
            $field->validators[] = new NumberRange(1);
        });
    }

    private function createProcessInTrackingRequestSetting()
    {
        return $this->makeSetting('processDuringTrackingRequest', $default = true, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = Piwik::translate('QueuedTracking_ProcessDuringRequestFieldTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->inlineHelp = Piwik::translate('QueuedTracking_ProcessDuringRequestFieldHelp', ['<code>', '</code>']) . '</br>';
        });
    }

    public function checkMultipleServersOnlyConfiguredWhenSentinelIsEnabled($value)
    {
        if ($this->isUsingSentinelBackend() or $this->isUsingClusterBackend()) {
            return;
        }

        $values = $this->convertCommaSeparatedValueToArray($value);

        if (count($values) > 1) {
            throw new Exception(Piwik::translate('QueuedTracking_MultipleServersOnlyConfigurableIfSentinelEnabled'));
        }
    }

    public function convertCommaSeparatedValueToArray($value)
    {
        if ($value === '' || $value === false || $value === null) {
            return array();
        }

        $values = explode(',', $value);
        $values = array_map('trim', $values);

        return $values;
    }

    public function isRedisBackend()
    {
        return $this->backend->getValue() !== 'mysql';
    }

    public function isMysqlBackend()
    {
        return $this->backend->getValue() === 'mysql';
    }

    private function createBackendSetting()
    {
        return $this->makeSetting('backend', $default = 'redis', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('QueuedTracking_BackendSettingFieldTitle');
            $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
            $field->availableValues = array('redis' => 'Redis', 'mysql' => 'MySQL');
            $field->inlineHelp = Piwik::translate('QueuedTracking_BackendSettingFieldHelp') . '</br>';
        });
    }

    private function createUseWhatRedisBackendType()
    {
        return $this->makeSetting('useWhatRedisBackendType', $default = 1, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = 'Redis type';
            $field->uiControl = FieldConfig::UI_CONTROL_RADIO;
            $field->availableValues = $this->availableRedisBackendType;
            $field->condition = 'backend=="redis"';
            $field->inlineHelp = Piwik::translate('QueuedTracking_WhatRedisBackEndType') . '</br>';
        });
    }

    private function createSetSentinelMasterName()
    {
        return $this->makeSetting('sentinelMasterName', $default = 'mymaster', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = Piwik::translate('QueuedTracking_MasterNameFieldTitle');
            $field->condition = 'backend=="redis"';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 200);
            $field->inlineHelp = Piwik::translate('QueuedTracking_MasterNameFieldHelp') . '</br>';
            $field->validators[] = new CharacterLength(0, 200);
            $field->transform = function ($value) {
                if (empty($value)) {
                    return '';
                }
                return trim($value);
            };
        });
    }

    public function checkMatchHostsAndPorts()
    {
        $hosts = $this->redisHost->getValue();
        $ports = $this->redisPort->getValue();
        $numHosts = count(explode(',', $hosts));
        $numPorts = count(explode(',', $ports));

        if (($hosts || $ports) && $numHosts !== $numPorts) {
            throw new Exception(Piwik::translate('QueuedTracking_NumHostsNotMatchNumPorts'));
        }
    }

    public function save()
    {
        $this->checkMatchHostsAndPorts();

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
