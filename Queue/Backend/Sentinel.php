<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking\Queue\Backend;

use Piwik\Plugins\QueuedTracking\Queue\Backend;
use Piwik\Tracker;

include_once PIWIK_INCLUDE_PATH . '/plugins/QueuedTracking/libs/credis/Client.php';
include_once PIWIK_INCLUDE_PATH . '/plugins/QueuedTracking/libs/credis/Cluster.php';
include_once PIWIK_INCLUDE_PATH . '/plugins/QueuedTracking/libs/credis/Sentinel.php';

class Sentinel extends Redis
{
    private $masterName = '';

    protected function connect()
    {
        $configuredClient = new \Credis_Client($this->host, $this->port, $timeout = 1.5, $persistent = false);
        $configuredSentinel = new \Credis_Sentinel($configuredClient);
        $master = $configuredSentinel->getMasterAddressByName($this->masterName);

        $client = new \Credis_Client($master[0], $master[1], $this->timeout, $persistent = false, $this->database, $this->password);
        $client->connect();

        $this->redis = $client;

        return true;
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
