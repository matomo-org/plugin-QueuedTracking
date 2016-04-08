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
    protected function connect()
    {
        $client = new \Credis_Client($this->host, $this->port, $this->timeout, $persistent = false, $this->database, $this->password);
        $this->redis = new \Credis_Sentinel($client);
        $client->connect();

        $this->redis = $client;

        return true;
    }

    protected function evalScript($script, $keys, $args)
    {
        return $this->redis->eval($script, $keys, $args);
    }

}
