<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * 
 */
namespace Piwik\Plugins\QueuedTracking\Queue\Backend;

use Piwik\Log;
use Piwik\Plugins\QueuedTracking\Queue\Backend;

class RedisCluster extends Redis
{
    /**
     * @var \RedisCluster
     */
    protected $redis;
    protected $host;
    protected $port;
    protected $timeout;
    protected $password;

    /**
     * @var int
     */
    protected $database;

    public function testConnection()
    {
        try {
            $this->connectIfNeeded();
            return 'TEST' === $this->redis->echo('TEST_ECHO', 'TEST');

        } catch (\Exception $e) {
            Log::debug($e->getMessage());
        }

        return false;
    }

    public function getServerVersion()
    {
        $this->connectIfNeeded();

        $server = $this->redis->info('server');

        if (empty($server)) {
            return '';
        }

        $version = $server['redis_version'];

        return $version;
    }

    public function getLastError()
    {
        $this->connectIfNeeded();

        return $this->redis->getLastError();
    }

    /**
     * Returns converted bytes to B,K,M,G,T.
     * @param int|float|double $bytes byte number.
     * @param int $precision decimal round.
     * * @return string
     */    
    private function formatBytes($bytes, $precision = 2) { 
        $units = array('B', 'K', 'M', 'G', 'T'); 
       
        $bytes = max($bytes, 0); 
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
        $pow = min($pow, count($units) - 1); 
        $bytes /= (1 << (10 * $pow)); 
       
        return round($bytes, $precision) . $units[$pow]; 
    }     

    public function getMemoryStats()
    {
        $this->connectIfNeeded();

        $hosts = explode(',', $this->host);
        $ports = explode(',', $this->port);

        $memory = array
        (
            'used_memory_human' => 0,
            'used_memory_peak_human' => 0
        );

        foreach ($hosts as $idx=>$host) 
        {
            $info = $this->redis->info(array($host, (int)$ports[$idx]), 'memory');
            $memory['used_memory_human'] += $info['used_memory'] ?? 0;
            $memory['used_memory_peak_human'] += $info['used_memory_peak'] ?? 0;
        }

        $memory['used_memory_human'] = $this->formatBytes($memory['used_memory_human']);
        $memory['used_memory_peak_human'] = $this->formatBytes($memory['used_memory_peak_human']);

        return $memory;
    }

    /**
     * Returns the time to live of a key that can expire in ms.
     * @param $key
     * @return int
     */
    public function getTimeToLive($key)
    {
        $this->connectIfNeeded();

        $ttl = $this->redis->pttl($key);

        if ($ttl == -1) {
            // key exists but has no associated expire
            return 99999999;
        }

        if ($ttl == -2) {
            // key does not exist
            return 0;
        }

        return $ttl;
    }

    public function appendValuesToList($key, $values)
    {
        $this->connectIfNeeded();

        foreach ($values as $value) {
            $this->redis->rPush($key, gzcompress($value));
        }

        // usually we would simply do call_user_func_array(array($redis, 'rPush'), $values); as rpush supports multiple values
        // at once but it seems to be not implemented yet see https://github.com/nicolasff/phpredis/issues/366
        // doing it in one command should be much faster as it requires less tcp communication. Anyway, we currently do
        // not write multiple values at once ... so it is ok!
    }

    public function getFirstXValuesFromList($key, $numValues)
    {
        if ($numValues <= 0) {
            return array();
        }

        $this->connectIfNeeded();
        $values = $this->redis->lRange($key, 0, $numValues - 1);
        foreach($values as $key => $value) {
            $tmpValue = @gzuncompress($value); // Avoid warning if not compressed
            
            // if empty, string is not compressed. Use original value
            if(empty($tmpValue)) {
                $values[$key] = $value;
            } else {
                $values[$key] = $tmpValue;
            }
        }

        return $values;
    }

    public function removeFirstXValuesFromList($key, $numValues)
    {
        if ($numValues <= 0) {
            return;
        }

        $this->connectIfNeeded();
        $this->redis->ltrim($key, $numValues, -1);
    }

    public function hasAtLeastXRequestsQueued($key, $numValuesRequired)
    {
        if ($numValuesRequired <= 0) {
            return true;
        }

        $numActual = $this->getNumValuesInList($key);

        return $numActual >= $numValuesRequired;
    }

    public function getNumValuesInList($key)
    {
        $this->connectIfNeeded();

        return $this->redis->lLen($key);
    }

    public function setIfNotExists($key, $value, $ttlInSeconds)
    {
        $this->connectIfNeeded();
        $wasSet = $this->redis->set($key, $value, array('nx', 'ex' => $ttlInSeconds));

        return $wasSet;
    }

    /**
     * @internal for tests only
     * @return \RedisCluster
     */
    public function getConnection()
    {
        return $this->redis;
    }

    /**
     * @internal for tests only
     */
    public function delete($key)
    {
        $this->connectIfNeeded();

        return $this->redis->del($key) > 0;
    }

    public function deleteIfKeyHasValue($key, $value)
    {
        if (empty($value)) {
            return false;
        }

        $this->connectIfNeeded();

        // see http://redis.io/topics/distlock
        $script = 'if redis.call("GET",KEYS[1]) == ARGV[1] then
    return redis.call("DEL",KEYS[1])
else
    return 0
end';

        // ideally we would use evalSha to reduce bandwidth!
        return (bool) $this->evalScript($script, array($key), array($value));
    }

    protected function evalScript($script, $keys, $args)
    {
        return $this->redis->eval($script, array_merge($keys, $args), count($keys));
    }

    public function getKeysMatchingPattern($pattern)
    {
        $this->connectIfNeeded();

        return $this->redis->keys($pattern);
    }

    public function expireIfKeyHasValue($key, $value, $ttlInSeconds)
    {
        if (empty($value)) {
            return false;
        }

        $this->connectIfNeeded();

        $script = 'if redis.call("GET",KEYS[1]) == ARGV[1] then
    return redis.call("EXPIRE",KEYS[1], ARGV[2])
else
    return 0
end';
        // ideally we would use evalSha to reduce bandwidth!
        return (bool) $this->evalScript($script, array($key), array($value, (int) $ttlInSeconds));
    }

    public function get($key)
    {
        $this->connectIfNeeded();

        return $this->redis->get($key);
    }

    /**
     * @internal
     */
    public function flushAll()
    {
        $this->connectIfNeeded();

        $hosts = explode(',', $this->host);
        $ports = explode(',', $this->port);

        foreach ($hosts as $idx=>$host) 
        {$this->redis->flushDB(array($host, (int)$ports[$idx]));}
    }

    private function connectIfNeeded()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }
    }

    protected function connect()
    {
        $hosts = explode(',', $this->host);
        $ports = explode(',', $this->port);

        if (count($hosts) !== count($ports)) {
            throw new Exception(Piwik::translate('QueuedTracking_NumHostsNotMatchNumPorts'));
        }

        $hostsPorts = array_map(fn($host, $port): string => "$host:$port", $hosts, $ports);

        try {
            $this->redis = new \RedisCluster(NULL, $hostsPorts, $this->timeout, $this->timeout, true, $this->password);       
            return true;
        } catch (Exception $e) {
            throw new Exception('Could not connect to redis cluster: ' . $e->getMessage());
        }
    }

    public function setConfig($host, $port, $timeout, $password)
    {
        $this->disconnect();

        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;

        if (!empty($password)) {
            $this->password = $password;
        }
    }

    private function disconnect()
    {
        if ($this->isConnected()) {
            $this->redis->close();
        }

        $this->redis = null;
    }

    private function isConnected()
    {
        return isset($this->redis);
    }

    public function setDatabase($database)
    {
        $this->database = $database;
    }
}
