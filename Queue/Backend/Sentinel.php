<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking\Queue\Backend;

use Piwik\Log;
use Piwik\Piwik;
use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Tracker;
use Exception;

include_once __DIR__ . '/../../libs/credis/Client.php';
include_once __DIR__ . '/../../libs/credis/Cluster.php';
include_once __DIR__ . '/../../libs/credis/Sentinel.php';

class Sentinel extends Redis
{
    private $masterName = '';

    protected function connect()
    {
        $hosts = explode(',', $this->host);
        $ports = explode(',', $this->port);

        if (count($hosts) !== count($ports)) {
            throw new Exception(Piwik::translate('QueuedTracking_NumHostsNotMatchNumPorts'));
        }

        foreach ($hosts as $index => $host) { // Sort or randomize as appropriate
            try {
                $configuredClient = new \Credis_Client($host, $ports[$index], $timeout = 0.5, $persistent = false);
                $configuredClient->forceStandalone();
                $configuredClient->connect();
                $configuredSentinel = new \Credis_Sentinel($configuredClient);
                $master = $configuredSentinel->getMasterAddressByName($this->masterName);

                if (!empty($master)) {
                    if (!class_exists('\Redis') && $this->timeout == 0) {
                        $this->timeout === 0.05;
                    }

                    $client = new \Credis_Client($master[0], $master[1], $this->timeout, $persistent = false, $this->database, $this->password);
                    $client->connect();

                    $this->redis = $client;

                    return true;
                }

            } catch (Exception $e) {
                Log::debug($e->getMessage());
            }
        }

        throw new Exception('Could not receive an actual master from sentinel');
    }

    public function setSentinelMasterName($name)
    {
        $this->masterName = $name;
    }

    protected function evalScript($script, $keys, $args)
    {
        return $this->redis->eval($script, $keys, $args);
    }

}
