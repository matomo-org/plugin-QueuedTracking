<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue\Backend;

use Piwik\Plugins\QueuedTracking\Queue\Backend\MySQL;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Redis;
use \Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group QueuedTracking
 * @group Redis
 * @group MysqlTest
 * @group Queue
 * @group Tracker
 */
class MysqlTest extends IntegrationTestCase
{
    /**
     * @var MySQL
     */
    private $backend;
    private $emptyListKey = 'testMyEmptyListTestKey';
    private $listKey = 'testMyListTestKey';
    private $key = 'testKeyValueKey';

    public function setUp()
    {
        if (!$this->hasDependencies()) {
            parent::setUp();
        }

        $this->backend = $this->createMysqlBackend();

        if (!$this->hasDependencies()) {
            $this->backend->delete($this->emptyListKey);
            $this->backend->delete($this->listKey);
            $this->backend->delete($this->key);
            $this->backend->appendValuesToList($this->listKey, array(10, 299, '34'));
        }
    }

    protected function flushBackend()
    {
        $backend = $this->createMysqlBackend();
        $backend->flushAll();
    }

    protected function createMysqlBackend()
    {
        return new MySQL();
    }

    public function test_appendValuesToList_shouldNotAddAnything_IfNoValuesAreGiven()
    {
        $this->backend->appendValuesToList($this->emptyListKey, array());

        $this->assertNumberOfItemsInList($this->emptyListKey, 0);

        $verify = $this->backend->getFirstXValuesFromList($this->emptyListKey, 1);
        $this->assertEquals(array(), $verify);
    }

    public function test_appendValuesToList_shouldAddOneValue_IfOneValueIsGiven()
    {
        $this->backend->appendValuesToList($this->emptyListKey, array(4));

        $verify = $this->backend->getFirstXValuesFromList($this->emptyListKey, 1);

        $this->assertEquals(array(4), $verify);
    }

    public function test_appendValuesToList_shouldBeAbleToAddMultipleValues()
    {
        $this->backend->appendValuesToList($this->emptyListKey, array(10, 299, '34'));
        $this->assertFirstValuesInList($this->emptyListKey, array(10, 299, '34'));
    }

    public function test_getFirstXValuesFromList_shouldReturnAnEmptyArray_IfListIsEmpty()
    {
        $this->assertFirstValuesInList($this->emptyListKey, array());
    }

    public function test_getFirstXValuesFromList_shouldReturnOnlyValuesFromTheBeginningOfTheList()
    {
        $this->assertFirstValuesInList($this->listKey, array(), 0);
        $this->assertFirstValuesInList($this->listKey, array(10), 1);
        $this->assertFirstValuesInList($this->listKey, array(10, 299), 2);
        $this->assertFirstValuesInList($this->listKey, array(10, 299, '34'), 3);
        $this->assertFirstValuesInList($this->listKey, array(10, 299, '34'));
    }

    public function test_removeFirstXValuesFromList_shouldNotReturnAnything_IfNumValueToRemoveIsZero()
    {
        $this->backend->removeFirstXValuesFromList($this->listKey, 0);
        $this->assertFirstValuesInList($this->listKey, array(10, 299, '34'));
    }

    public function test_removeFirstXValuesFromList_shouldBeAbleToRemoveOneValueFromTheBeginningOfTheList()
    {
        $this->backend->removeFirstXValuesFromList($this->listKey, 1);
        $this->assertFirstValuesInList($this->listKey, array(299, '34'));
    }

    public function test_removeFirstXValuesFromList_shouldBeAbleToRemoveMultipleValuesFromTheBeginningOfTheList()
    {
        $this->backend->removeFirstXValuesFromList($this->listKey, 2);
        $this->assertFirstValuesInList($this->listKey, array('34'));

        // remove one more
        $this->backend->removeFirstXValuesFromList($this->listKey, 1);
        $this->assertFirstValuesInList($this->listKey, array());
    }

    public function test_removeFirstXValuesFromList_ShouldNotFail_IfListIsEmpty()
    {
        $this->backend->removeFirstXValuesFromList($this->emptyListKey, 1);
        $this->assertFirstValuesInList($this->emptyListKey, array());
    }

    public function test_getNumValuesInList_shouldReturnZero_IfListIsEmpty()
    {
        $this->assertNumberOfItemsInList($this->emptyListKey, 0);
    }

    public function test_getNumValuesInList_shouldReturnNumberOfEntries_WhenListIsNotEmpty()
    {
        $this->backend->appendValuesToList($this->emptyListKey, array(12));
        $this->assertNumberOfItemsInList($this->emptyListKey, 1);

        $this->backend->appendValuesToList($this->emptyListKey, array(3, 99, '488'));
        $this->assertNumberOfItemsInList($this->emptyListKey, 4);
    }

    public function test_deleteIfKeyHasValue_ShouldNotWork_IfKeyDoesNotExist()
    {
        $success = $this->backend->deleteIfKeyHasValue('inVaLidKeyTest', '1');
        $this->assertFalse($success);
    }

    public function test_deleteIfKeyHasValue_ShouldWork_ShouldBeAbleToDeleteARegularKey()
    {
        $success = $this->backend->setIfNotExists($this->key, 'test', 60);
        $this->assertTrue($success);

        $success = $this->backend->deleteIfKeyHasValue($this->key, 'test');
        $this->assertTrue($success);
    }

    public function test_deleteIfKeyHasValue_ShouldNotWork_IfValueIsDifferent()
    {
        $this->backend->setIfNotExists($this->key, 'test', 60);

        $success = $this->backend->deleteIfKeyHasValue($this->key, 'test2');
        $this->assertFalse($success);
    }

    public function test_setIfNotExists_ShouldWork_IfNoValueIsSetYet()
    {
        $success = $this->backend->setIfNotExists($this->key, 'value', 60);
        $this->assertTrue($success);
    }

    /**
     * @depends test_setIfNotExists_ShouldWork_IfNoValueIsSetYet
     */
    public function test_setIfNotExists_ShouldNotWork_IfValueIsAlreadySet()
    {
        $success = $this->backend->setIfNotExists($this->key, 'value', 60);
        $this->assertFalse($success);
    }

    /**
     * @depends test_setIfNotExists_ShouldNotWork_IfValueIsAlreadySet
     */
    public function test_setIfNotExists_ShouldAlsoNotWork_IfTryingToSetDifferentValue()
    {
        $success = $this->backend->setIfNotExists($this->key, 'another val', 60);
        $this->assertFalse($success);
    }

    public function test_get_ShouldReturnFalse_IfKeyNotSet()
    {
        $value = $this->backend->get($this->key);
        $this->assertFalse($value);
    }

    public function test_get_ShouldReturnTheSetValue_IfOneIsSet()
    {
        $this->backend->setIfNotExists($this->key, 'mytest', 60);
        $value = $this->backend->get($this->key);
        $this->assertEquals('mytest', $value);
    }

    public function test_keyExists_ShouldReturnFalse_IfKeyNotSet()
    {
        $value = $this->backend->keyExists($this->key);
        $this->assertFalse($value);
    }

    public function test_get_ShouldReturnTrueIfValueIsSet()
    {
        $this->backend->setIfNotExists($this->key, 'mytest', 60);
        $this->assertTrue($this->backend->keyExists($this->key));
    }

    /**
     * @depends test_setIfNotExists_ShouldAlsoNotWork_IfTryingToSetDifferentValue
     */
    public function test_setIfNotExists_ShouldWork_AsSoonAsKeyWasDeleted()
    {
        $this->backend->delete($this->key);
        $success = $this->backend->setIfNotExists($this->key, 'another val', 60);
        $this->assertTrue($success);
    }

    public function test_expire_ShouldWork()
    {
        $success = $this->backend->setIfNotExists($this->key, 'test', 60);
        $this->assertTrue($success);

        $success = $this->backend->expireIfKeyHasValue($this->key, 'test', $seconds = 1);
        $this->assertTrue($success);

        // should not work as value still saved and not expired yet
        $success = $this->backend->setIfNotExists($this->key, 'test', 60);
        $this->assertFalse($success);

        sleep($seconds + 1);

        // value is expired and should work now!
        $success = $this->backend->setIfNotExists($this->key, 'test', 60);
        $this->assertTrue($success);
    }

    public function test_expire_ShouldNotWorkIfValueIsDifferent()
    {
        $success = $this->backend->setIfNotExists($this->key, 'test', 60);
        $this->assertTrue($success);

        $success = $this->backend->expireIfKeyHasValue($this->key, 'test2', $seconds = 1);
        $this->assertFalse($success);
    }

    public function test_getTimeToLive_ShouldReturnTheNumberOfTimeLeft_IfExpires()
    {
        $success = $this->backend->setIfNotExists($this->key, 'test', 60);
        $this->assertTrue($success);

        $timeToLive = $this->backend->getTimeToLive($this->key);
        $this->assertGreaterThanOrEqual(40000, $timeToLive);
    }

    public function test_getTimeToLive_ShouldReturn0OrLessIfNoExpire()
    {
        $timeToLive = $this->backend->getTimeToLive($this->key);
        $this->assertLessThanOrEqual(0, $timeToLive);
    }

    private function assertNumberOfItemsInList($key, $expectedNumber)
    {
        $numValus = $this->backend->getNumValuesInList($key);

        $this->assertSame($expectedNumber, $numValus);
    }

    private function assertFirstValuesInList($key, $expectedValues, $numValues = 999)
    {
        $verify = $this->backend->getFirstXValuesFromList($key, $numValues);

        $this->assertEquals($expectedValues, $verify);
    }

    public function test_checkConnectionDetails_shouldNotFailIfConnectionDataIsCorrect()
    {
        $success = $this->createMysqlBackend()->testConnection();
        $this->assertTrue($success);
    }

    public function test_getVersion_shouldReturnAVersionNumber()
    {
        $versionNumber = $this->createMysqlBackend()->getServerVersion();
        $this->assertNotEmpty($versionNumber, 'Could not get redis server version');

        $this->assertTrue(version_compare($versionNumber, '2.8.0') >= 0);
    }

    public function test_getMemoryStats_shouldReturnMemoryInformation()
    {
        $memory = $this->createMysqlBackend()->getMemoryStats();

        $this->assertArrayHasKey('used_memory_human', $memory);
        $this->assertArrayHasKey('used_memory_peak_human', $memory);
        $this->assertNotEmpty($memory['used_memory_human']);
    }

    public function test_getKeysMatchingPattern_shouldReturnMatchingKeys()
    {
        $backend = $this->createMysqlBackend();
        $backend->setIfNotExists('abcde', 'val0', 100);
        $backend->setIfNotExists('test1', 'val1', 100);
        $backend->setIfNotExists('Test3', 'val2', 100);
        $backend->setIfNotExists('Test1', 'val3', 100);
        $backend->setIfNotExists('Test2', 'val4', 100);

        $keys = $backend->getKeysMatchingPattern('Test*');
        sort($keys);
        $this->assertEquals(array('Test2', 'Test3', 'test1'), $keys);

        $keys = $backend->getKeysMatchingPattern('test1*');
        sort($keys);
        $this->assertEquals(array('test1'), $keys);

        $keys = $backend->getKeysMatchingPattern('*est*');
        sort($keys);
        $this->assertEquals(array('Test2', 'Test3', 'test1'), $keys);
    }

    public function test_getKeysMatchingPattern_shouldReturnAnEmptyArrayIfNothingMatches()
    {
        $backend = $this->createMysqlBackend();
        $keys    = $backend->getKeysMatchingPattern('*fere*');
        $this->assertEquals(array(), $keys);
    }

}
