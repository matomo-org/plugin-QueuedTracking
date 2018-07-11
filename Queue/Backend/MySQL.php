<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking\Queue\Backend;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Log;
use Piwik\Plugins\QueuedTracking\Queue\Backend;

class MySQL implements Backend
{
    const QUEUED_TRACKING_TABLE_PREFIX = 'queuedtracking_';

    private $table = 'queuedtracking_queue';
    private $tableListPrefix = 'queuedtracking_list_';
    private $tablePrefixed;

    public function __construct()
    {
        $this->tablePrefixed = Common::prefixTable($this->table);
    }

    public function install()
    {
        DbHelper::createTable($this->table, "
                  `queue_key` VARCHAR(70) NOT NULL,
                  `queue_value` VARCHAR(255) NULL DEFAULT NULL,
                  `expiry_time` BIGINT UNSIGNED DEFAULT 9999999999,
                  UNIQUE unique_queue_key (`queue_key`)");
    }

    public function uninstall()
    {
        Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $this->tablePrefixed));
    }

    private function makePrefixedKeyListTableName($key)
    {
        return Common::prefixTable($this->tableListPrefix . $key);
    }

    private function createListTable($key)
    {
        $table = $this->makePrefixedKeyListTableName($key);
        $createDefinition = "
                  `idqueuelist` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `list_value` LONGBLOB NOT NULL,
                  PRIMARY KEY (`idqueuelist`)";

        $dbSettings = new Db\Settings();
        $engine = $dbSettings->getEngine();

        $statement = sprintf("CREATE TABLE IF NOT EXISTS `%s` ( %s ) ENGINE=%s DEFAULT CHARSET=utf8 ;",
            $table,
            $createDefinition,
            $engine);

        // DbHelper::createTable() won't work in tracker mode!
        Db::get()->query($statement);
    }

    public function testConnection()
    {
        try {
            return '1' == Db::get()->fetchOne('SELECT 1');

        } catch (\Exception $e) {
            Log::debug($e->getMessage());
        }

        return false;
    }

    public function getServerVersion()
    {
        $db = Db::get();
        if (method_exists($db, 'getServerVersion')) {
            return $db->getServerVersion();
        }
        return Db::fetchOne('select version()');
    }

    public function getMemoryStats()
    {
        return array('used_memory_human' => 'disabled', 'used_memory_peak_human' => 'disabled');
    }

    public function appendValuesToList($key, $values)
    {
        $table = $this->makePrefixedKeyListTableName($key);

        $query = sprintf('INSERT INTO %s (`list_value`) VALUES (?)', $table);
        foreach ($values as $value) {
            if (empty($value)) {
                continue;
            }

            $value = gzcompress($value);

            try {
                Db::query($query, array($value));
            } catch (\Exception $e) {
                if ($this->isErrorTableNotExists($e)) {

                    // we create list tables only on demand
                    $this->createListTable($key);
                    Db::query($query, array($value));
                } else {
                    throw $e;
                }
            }
        }
    }

    private function isErrorTableNotExists(\Exception $e)
    {
        return strpos($e->getMessage(), ' 1146 ') !== false
            || strpos($e->getMessage(), " doesn't exist") !== false;
    }

    public function getFirstXValuesFromList($key, $numValues)
    {
        if ($numValues <= 0) {
            return array();
        }

        $table = $this->makePrefixedKeyListTableName($key);
        $sql = sprintf('SELECT SQL_NO_CACHE list_value FROM %s ORDER BY idqueuelist ASC LIMIT %d OFFSET 0', $table, (int)$numValues);

        try {
            $values = Db::fetchAll($sql);
        } catch (\Exception $e) {
            if ($this->isErrorTableNotExists($e)) {
                $values = array(); // no value inserted yet
            } else {
                throw $e;
            }
        }

        $raw = array();
        foreach ($values as $value) {
            if (!empty($value['list_value'])) {
                $raw[] = gzuncompress($value['list_value']);
            }
        }

        return $raw;
    }

    public function hasAtLeastXRequestsQueued($key, $numValuesRequired)
    {
        if ($numValuesRequired <= 0) {
            return true;
        }

        $table = $this->makePrefixedKeyListTableName($key);
        $sql = sprintf('SELECT SQL_NO_CACHE idqueuelist FROM %s LIMIT %d', $table, (int)$numValuesRequired);

        try {
            $values = Db::fetchAll($sql);
        } catch (\Exception $e) {
            if ($this->isErrorTableNotExists($e)) {
                return false;
            } else {
                throw $e;
            }
        }

        return count($values) >= $numValuesRequired;
    }

    public function removeFirstXValuesFromList($key, $numValues)
    {
        if ($numValues <= 0) {
            return;
        }

        $table = $this->makePrefixedKeyListTableName($key);
        $sql = sprintf('DELETE FROM %s ORDER BY idqueuelist ASC LIMIT %d', $table, (int)$numValues);

        try {
            Db::query($sql);
        } catch (\Exception $e) {
            if ($this->isErrorTableNotExists($e)) {
                // no value inserted yet
            } else {
                throw $e;
            }
        }
    }

    public function getNumValuesInList($key)
    {
        $table = $this->makePrefixedKeyListTableName($key);
        $sql = sprintf('SELECT SQL_NO_CACHE max(idqueuelist) - min(idqueuelist) as num_entries FROM %s', $table);
        try {
            $value = Db::fetchOne($sql);
            if ($value === null || $value === false) {
                return 0;
            }
            // we need to add one more, for example imagine min(id) = 1, max(id) = 1... then it does 1-1=0 but it is 1
            // or when min(id) = 5 and max(id) = 8 then 8-5 = 3 but there are 4 values.
            $value++;
            return $value;
        } catch (\Exception $e) {
            if ($this->isErrorTableNotExists($e)) {
                // no value inserted yet
                return 0;
            } else {
                throw $e;
            }
        }
    }

    /**
     * @internal for tests only
     * @return \Piwik\Tracker\Db|\Piwik\Db\AdapterInterface|\Piwik\Db
     */
    public function getConnection()
    {
        return Db::get();
    }

    public function getLastError()
    {
        return json_encode(Db::fetchAll('SHOW ERRORS'));
    }

    public function setIfNotExists($key, $value, $ttlInSeconds)
    {
        if (empty($ttlInSeconds)) {
            $ttlInSeconds = 999999999;
        }

        // FYI: We used to have an INSERT INTO ... ON DUPLICATE UPDATE ... However, this can be problematic in concurrency issues
        // because the ON DUPLICATE UPDATE may work successfully for 2 jobs at the same time but only one of them got the lock then.
        // This would be perfectly fine if we did something like `return $this->get($key) === $value` to 100% detect which process
        // got the lock as we do now. However, maybe the expireTime gets overwritten with a wrong value or so. That's why we
        // rather try to get the lock with the insert only because only one job can succeed with this. If below flow with the
        // delete becomes to slow, we may be able to use the INSERT INTO ... ON DUPLICATE UPDATE again.

        if ($this->get($key)) {
            return false; // a value is set, won't be possible to insert
        }

        // remove any existing but expired lock
        // todo: we could combine get() and keyExists() in one query!
        if ($this->keyExists($key)) {
            // most of the time an expired key should not exist... we don't want to lock the row unncessarily therefore we check first
            // if value exists... 
            $sql = sprintf('DELETE FROM %s WHERE queue_key = ? and not (%s)', $this->tablePrefixed, $this->getQueryPartExpiryTime());
            Db::query($sql, array($key));
        }

        $query = sprintf('INSERT INTO %s (`queue_key`, `queue_value`, `expiry_time`) 
                                 VALUES (?,?,(UNIX_TIMESTAMP() + ?))',
            $this->tablePrefixed);
        // we make sure to update the row if the key is expired and consider it as "deleted"

        try {
            $query = Db::query($query, array($key, $value, (int) $ttlInSeconds));
        } catch (\Exception $e) {
            if ($e->getCode() == 23000
                || strpos($e->getMessage(), 'Duplicate entry') !== false
                || strpos($e->getMessage(), ' 1062 ') !== false) {
                return false;
            }
            throw $e;
        }

        // we make sure we got the lock
        return $this->get($key) === $value;
    }

    /**
     * Returns the time to live of a key that can expire in ms.
     * @param $key
     * @return int
     */
    public function getTimeToLive($key)
    {
        $sql = sprintf('SELECT SQL_NO_CACHE expiry_time, UNIX_TIMESTAMP() as timestamp FROM %s WHERE queue_key = ? LIMIT 1', $this->tablePrefixed);
        $row = Db::fetchRow($sql, array($key));

        if (empty($row)) {
            // key does not exist
            return 0;
        }

        if (empty($row['expiry_time'])) {
            // key exists but has no associated expire
            return 99999999;
        }

        $secondsLeft = $row['expiry_time'] - $row['timestamp'];
        if ($secondsLeft <= 0) {
            return 0;// expired => key does not exist anymore
        }

        $msLeft = $secondsLeft * 1000;
        // we return it in MS. We could store `ROUND(UNIX_TIMESTAMP(CURTIME(4)) * 1000)` however requires Mysql 5.6+

        return $msLeft;
    }

    /**
     * @internal for tests only
     */
    public function delete($key)
    {
        $sql = sprintf('DELETE FROM %s WHERE queue_key = ?', $this->tablePrefixed);
        $wasDeleted = (bool) Db::query($sql, array($key))->rowCount();

        $table = $this->makePrefixedKeyListTableName($key);
        $wasDeleted = $this->dropTable($table) || $wasDeleted; // we return true if either list was removed or value

        return $wasDeleted;
    }

    private function dropTable($table)
    {
        $wasDeleted = (bool)Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $table))->rowCount();
        return $wasDeleted;
    }

    private function getQueryPartExpiryTime()
    {
        return 'UNIX_TIMESTAMP() <= expiry_time';
    }

    public function deleteIfKeyHasValue($key, $value)
    {
        if (empty($value)) {
            return false;
        }

        $sql = sprintf('DELETE FROM %s WHERE queue_key = ? and queue_value = ?', $this->tablePrefixed);
        return (bool) Db::query($sql, array($key, $value))->rowCount();
    }

    /**
     * fyi: does not support list keys at the moment just because not really needed so much just yet
     */
    public function getKeysMatchingPattern($pattern)
    {
        $sql = sprintf('SELECT SQL_NO_CACHE distinct queue_key FROM %s WHERE queue_key like ? and %s', $this->tablePrefixed, $this->getQueryPartExpiryTime());
        $pattern = str_replace('*', '%', $pattern);
        $keys = Db::fetchAll($sql, array($pattern));
        $raw = array();
        foreach ($keys as $key) {
            $raw[] = $key['queue_key'];
        }
        return $raw;
    }

    public function expireIfKeyHasValue($key, $value, $ttlInSeconds)
    {
        if (empty($value)) {
            return false;
        }

        // we need to use unix_timestamp in mysql and not time() in php since the local time might be different on each server
        // better to rely on one central DB server time only
        $sql = sprintf('UPDATE %s SET expiry_time = (UNIX_TIMESTAMP() + ?) WHERE queue_key = ? and queue_value = ?', $this->tablePrefixed);
        $success = (bool) Db::query($sql, array((int) $ttlInSeconds, $key, $value))->rowCount();

        if (!$success) {
            // the above update did not work because the same time was already set and we just tried to set the same ttl
            // again too fast within one second
            return $value === $this->get($key);
        }

        return true;
    }

    public function get($key)
    {
        $sql = sprintf('SELECT SQL_NO_CACHE queue_value FROM %s WHERE queue_key = ? AND %s LIMIT 1', $this->tablePrefixed, $this->getQueryPartExpiryTime());
        return Db::fetchOne($sql, array($key));
    }

    public function keyExists($key)
    {
        $sql = sprintf('SELECT SQL_NO_CACHE 1 FROM %s WHERE queue_key = ? LIMIT 1', $this->tablePrefixed);
        $value = Db::fetchOne($sql, array($key));
        return !empty($value);
    }

    /**
     * @internal
     */
    public function flushAll()
    {
        Db::query('DELETE FROM ' . $this->tablePrefixed);

        $db = Db::get();
        $listPrefix = Common::prefixTable($this->tableListPrefix);

        $tables = $db->fetchCol("SHOW TABLES LIKE '$listPrefix%'");
        foreach ($tables as $table) {
            $this->dropTable($table);
        }
    }

}
