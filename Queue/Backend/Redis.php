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

class Redis implements Backend
{
    private static $deleteScriptSha1 = null;
    private static $expireScriptSha1 = null;

    private const DELETE_SCRIPT_CONTENT = 'if redis.call("GET",KEYS[1]) == ARGV[1] then
    return redis.call("DEL",KEYS[1])
else
    return 0
end';

    private const EXPIRE_SCRIPT_CONTENT = 'if redis.call("GET",KEYS[1]) == ARGV[1] then
    return redis.call("EXPIRE",KEYS[1], ARGV[2])
else
    return 0
end';

    /**
     * @var \Redis
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
            return 'TEST' === $this->redis->echo('TEST');
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

    public function getMemoryStats()
    {
        $this->connectIfNeeded();

        $memory = $this->redis->info('memory');

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
        foreach ($values as $key => $value) {
            $tmpValue = @gzuncompress($value); // Avoid warning if not compressed

            // if empty, string is not compressed. Use original value
            if (empty($tmpValue)) {
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
     * @return \Redis
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

        if (self::$deleteScriptSha1 === null) {
            self::$deleteScriptSha1 = sha1(self::DELETE_SCRIPT_CONTENT);
        }

        try {
            return (bool) $this->redis->evalSha(self::$deleteScriptSha1, array($key, $value), 1);
        } catch (\RedisException $e) {
            if (strpos($e->getMessage(), 'NOSCRIPT') !== false) {
                return (bool) $this->redis->eval(self::DELETE_SCRIPT_CONTENT, array($key, $value), 1);
            }
            throw $e;
        }
    }

    public function getKeysMatchingPattern($pattern)
    {
        $this->connectIfNeeded();

        return $this->redis->keys($pattern) ?: [];
    }

    public function expireIfKeyHasValue($key, $value, $ttlInSeconds)
    {
        if (empty($value)) {
            return false;
        }

        $this->connectIfNeeded();

        if (self::$expireScriptSha1 === null) {
            self::$expireScriptSha1 = sha1(self::EXPIRE_SCRIPT_CONTENT);
        }

        try {
            return (bool) $this->redis->evalSha(self::$expireScriptSha1, array($key, $value, (int) $ttlInSeconds), 1);
        } catch (\RedisException $e) {
            if (strpos($e->getMessage(), 'NOSCRIPT') !== false) {
                return (bool) $this->redis->eval(self::EXPIRE_SCRIPT_CONTENT, array($key, $value, (int) $ttlInSeconds), 1);
            }
            throw $e;
        }
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
        $this->redis->flushDB();
    }

    private function connectIfNeeded()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }
    }

    protected function connect()
    {
        $this->redis = new \Redis();
        $success = $this->redis->connect($this->host, $this->port, $this->timeout, null, 100);

        if ($success && !empty($this->password)) {
            $success = $this->redis->auth($this->password);
        }

        if (!empty($this->database) || 0 === $this->database) {
            $this->redis->select($this->database);
        }

        return $success;
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
