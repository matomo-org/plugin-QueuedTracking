<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;

use Piwik\Plugins\QueuedTracking\SystemSettings;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tests\Framework\Fixture;

/**
 * @group QueuedTracking
 * @group SettingsTest
 * @group Plugins
 * @group Tracker
 */
class SettingsTest extends IntegrationTestCase
{
    /**
     * @var SystemSettings
     */
    private $settings;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();

        $this->settings = new SystemSettings();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_redisHost_ShouldFail_IfMoreThan300CharctersGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('should contain at most 500 characters');

        $this->settings->redisHost->setValue(str_pad('3', 503, '4'));
    }

    public function test_redisPort_ShouldFail_IfPortIsTooLow()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value needs to be at least 0');

        $this->settings->redisPort->setValue(-1);
    }

    public function test_redisPort_ShouldFail_IfPortIsTooHigh()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value should be at most 65535');

        $this->settings->redisPort->setValue(65536);
    }

    public function test_redisPort_ShouldNotFail_IfPortIsZero()
    {
        $this->settings->redisPort->setValue(0);
        $this->assertEquals(0, $this->settings->redisPort->getValue());
    }

    public function test_redisTimeout_ShouldFail_IfTooLong()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('should contain at most 5 characters');

        $this->settings->redisTimeout->setIsWritableByCurrentUser(true);
        $this->settings->redisTimeout->setValue('333.43');
    }

    public function test_redisTimeout_ShouldFail_IfNotNumeric()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value is not a number');

        $this->settings->redisTimeout->setIsWritableByCurrentUser(true);
        $this->settings->redisTimeout->setValue('33d3.43');
    }

    public function test_sentinelMasterName_ShouldFail_IfTooManyCharacters()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('should contain at most 200 characters');

        $this->settings->sentinelMasterName->setValue(str_pad('1', 201, '1'));
    }

    public function test_redisPassword_ShouldFail_IfMoreThan128CharctersGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('should contain at most 128 characters');

        $this->settings->redisPassword->setValue(str_pad('4', 130, '4'));
    }

    public function test_queueEnabled_ShouldFail_IfEnabledButWrongConnectionDetail()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Connection to Redis failed');

        $this->settings->redisPort->setValue(6378);
        $this->settings->queueEnabled->setValue(true);
    }

    public function test_queueEnabled_ShouldNotFail_IfEnabledButWrongConnectionDetail()
    {
        $this->settings->redisPort->setValue(6378);
        $this->settings->queueEnabled->setValue(false);

        $this->assertFalse($this->settings->queueEnabled->getValue());
    }

    public function test_numRequestsToProcess_ShouldFail_IfTooLow()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value needs to be at least 1');

        $this->settings->numRequestsToProcess->setValue(0);
    }

    public function test_numRequestsToProcess_ShouldFail_IfNotNumeric()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value is not a number');

        $this->settings->numRequestsToProcess->setValue('33d3.43');
    }

    public function test_redisDatabase_ShouldFail_IfIsNumericButFloat()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value is not an integer');

        $this->settings->redisDatabase->setValue('5.34');
    }

    public function test_redisDatabase_ShouldFail_IfNotNumeric()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value is not a number');

        $this->settings->redisDatabase->setValue('33d3.43');
    }

    public function test_redisDatabase_ShouldFail_IfTooLong()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('should contain at most 5 characters');

        $this->settings->redisDatabase->setValue('333333');
    }

    public function test_numQueueWorkers_ShouldFail_IfNotNumeric()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value is not a number');

        $this->settings->numQueueWorkers->setValue('1f');
    }

    public function test_numQueueWorkers_ShouldFail_IfIsNumericButFloat()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value is not an integer');

        $this->settings->numQueueWorkers->setValue('1.2');
    }

    public function test_numQueueWorkers_ShouldFail_IfTooHigh()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value should be at most 4096');

        $this->settings->numQueueWorkers->setValue('4097');
    }

    public function test_numQueueWorkers_ShouldFail_IfTooLow()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value needs to be at least 1');

        $this->settings->numQueueWorkers->setValue('0');
    }

    public function test_redisDatabase_ShouldWorkAndCastValueToInt_IfAcceptedValue()
    {
        $this->settings->redisDatabase->setValue('14');

        $this->assertSame(14, $this->settings->redisDatabase->getValue());
    }

    public function test_redisDatabase_ShouldBeEmptyByDefault()
    {
        $this->assertEmpty($this->settings->redisDatabase->getValue());
    }

    public function test_redisTimeout_ShouldBeNotUnlimitedByDefault()
    {
        $this->assertSame(0.0, $this->settings->redisTimeout->getValue());
    }

    public function test_redisTimeout_ShouldConvertAValueToFloat()
    {
        $this->settings->redisTimeout->setIsWritableByCurrentUser(true);
        $this->settings->redisTimeout->setValue('4.45');
        $this->assertSame(4.45, $this->settings->redisTimeout->getValue());
    }

    public function test_redisTimeout_ShouldNotBeWritableByDefault()
    {
        $this->assertFalse($this->settings->redisTimeout->isWritableByCurrentUser());
    }

    public function test_redisPort_ShouldConvertAValueToInt()
    {
        $this->settings->redisPort->setValue('4.45');
        $this->assertSame('4', $this->settings->redisPort->getValue());
    }

    public function test_sentinelMasterName_ShouldTrimTheGivenValue_IfNotEmpty()
    {
        $this->settings->sentinelMasterName->setValue('');
        $this->assertSame('', $this->settings->sentinelMasterName->getValue());

        $this->settings->sentinelMasterName->setValue(' test ');
        $this->assertSame('test', $this->settings->sentinelMasterName->getValue());
    }

    public function test_useSentinelBackend()
    {
        $this->settings->useSentinelBackend->setValue('0');
        $this->assertFalse($this->settings->useSentinelBackend->getValue());

        $this->settings->useSentinelBackend->setValue('1');
        $this->assertTrue($this->settings->useSentinelBackend->getValue());
    }

    public function test_redisPort_ShouldFailWhenMultipleValuesGiven_IfSentinelNotEnabled()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Multiple hosts or ports can be only configured when Redis Sentinel is on.');

        $this->settings->redisPort->setValue('45,56,788');
    }

    public function test_redisPort_ShouldNotFailAndConvertToIntWhenMultipleValuesGiven_IfSentinelIsEnabled()
    {
        $this->enableRedisSentinel();
        $this->settings->redisPort->setValue('55 , 44.34, 0 ');
        $this->assertSame('55,44,0', $this->settings->redisPort->getValue());
    }

    public function test_redisPort_ShouldValidateEachPortSeparately_WhenManySpecified()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The value is not a number');

        $this->enableRedisSentinel();
        $this->settings->redisPort->setValue('55, 0 , 44.34, 4mk ');
        $this->assertSame('55,44', $this->settings->redisPort->getValue());
    }

    public function test_redisHost_ShouldFailWhenMultipleValuesGiven_IfSentinelNotEnabled()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Multiple hosts or ports can be only configured when Redis Sentinel is on.');

        $this->settings->redisHost->setValue('10.0.0.1,127.0.0.1');
    }

    public function test_redisHost_ShouldNotFailAndTrimWhenMultipleValuesGiven_IfSentinelIsEnabled()
    {
        $this->enableRedisSentinel();
        $this->settings->redisHost->setValue('10.0.0.1 , 127.0.0.2 ');
        $this->assertSame('10.0.0.1,127.0.0.2', $this->settings->redisHost->getValue());
    }

    public function test_queueEnabled_ShouldBeDisabledByDefault()
    {
        $this->assertFalse($this->settings->queueEnabled->getValue());
    }

    public function test_useSentinelBackend_ShouldBeDisabledByDefault()
    {
        $this->assertFalse($this->settings->useSentinelBackend->getValue());
    }

    public function test_sentinelMasterName_shouldHaveValueByDefault()
    {
        $this->assertSame('mymaster', $this->settings->sentinelMasterName->getValue());
    }

    public function test_queueEnabled_ShouldConvertAnyValueToBool()
    {
        $this->settings->queueEnabled->setValue('4');
        $this->assertTrue($this->settings->queueEnabled->getValue());
    }

    public function test_numRequestsToProcess_ShouldBe50ByDefault()
    {
        $this->assertSame(25, $this->settings->numRequestsToProcess->getValue());
    }

    public function test_numRequestsToProcess_ShouldConvertAnyValueToInteger()
    {
        $this->settings->numRequestsToProcess->setValue('34');
        $this->assertSame(34, $this->settings->numRequestsToProcess->getValue());
    }

    public function test_numQueueWorkers_DefaultValue()
    {
        $this->assertSame(1, $this->settings->numQueueWorkers->getValue());
    }

    public function test_numQueueWorkers_ShouldConvertAnyValueToInteger()
    {
        $this->settings->numQueueWorkers->setValue('5');
        $this->assertSame(5, $this->settings->numQueueWorkers->getValue());
    }

    public function test_processDuringTrackingRequest_ShouldBeEnabledByDefault()
    {
        $this->assertTrue($this->settings->processDuringTrackingRequest->getValue());
    }

    public function test_processDuringTrackingRequest_ShouldConvertAnyValueToBoolean()
    {
        $this->settings->processDuringTrackingRequest->setValue('1');
        $this->assertTrue($this->settings->processDuringTrackingRequest->getValue());
    }

    public function test_isUsingSentinelBackend()
    {
        $this->disableRedisSentinel();

        $this->assertFalse($this->settings->isUsingSentinelBackend());

        $this->enableRedisSentinel('mymaster');

        $this->assertTrue($this->settings->isUsingSentinelBackend());
    }

    public function test_isUsingSentinelBackend_shouldBeEnabled_IfNoMasterNameIsConfigured()
    {
        $this->enableRedisSentinel('');

        $this->assertTrue($this->settings->isUsingSentinelBackend());
    }

    public function test_getSentinelMasterName()
    {
        $this->disableRedisSentinel();

        // default
        $this->assertSame('mymaster', $this->settings->getSentinelMasterName());

        // custom
        $this->enableRedisSentinel('mytest');

        $this->assertSame('mytest', $this->settings->getSentinelMasterName());

        // value configured
        $this->disableRedisSentinel();
        $this->settings->sentinelMasterName->setValue('test2');

        $this->assertSame('test2', $this->settings->getSentinelMasterName());
    }

    /**
     * @dataProvider getCommaSeparatedValues
     */
    public function test_convertCommaSeparatedValueToArray($stringValue, $expectedArray)
    {
        $this->assertSame($expectedArray, $this->settings->convertCommaSeparatedValueToArray($stringValue));
    }

    /**
     * @dataProvider getCommaSeparatedWithMultipleValues
     */
    public function test_checkMultipleServersOnlyConfiguredWhenSentinelIsEnabled_shouldFailWhenMoreThanOneValue_IfSentinelNotEnabled($stringValue)
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Multiple hosts or ports can be only configured when Redis Sentinel is on.');

        $this->disableRedisSentinel();

        $this->settings->checkMultipleServersOnlyConfiguredWhenSentinelIsEnabled($stringValue);
    }

    /**
     * @dataProvider getCommaSeparatedValues
     */
    public function test_checkMultipleServersOnlyConfiguredWhenSentinelIsEnabled_shouldNeverFail_IfSentinelIsEnabled($stringValue, $expectedArray)
    {
        $this->enableRedisSentinel();
        $this->settings->checkMultipleServersOnlyConfiguredWhenSentinelIsEnabled($stringValue);
        $this->assertTrue(true);
    }

    public function getCommaSeparatedWithMultipleValues()
    {
        return array(
            array('test,test2'),
            array('foo, bar , baz'),
            array('foo,bar,baz')
        );
    }

    public function test_save_shouldFailIfPortAndHostMismatch()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The number of configured hosts doesn\'t match the number of configured ports.');

        $this->enableRedisSentinel();
        $this->settings->redisPort->setValue('6379,6480,4393');
        $this->settings->redisHost->setValue('127.0.0.1,127.0.0.2');
        $this->settings->save();
    }

    public function getCommaSeparatedValues()
    {
        return array(
            array(false, array()),
            array(null, array()),
            array('', array()),
            array('test', array('test')),
            array('foo, bar , baz', array('foo', 'bar', 'baz')),
            array('foo,bar,baz', array('foo', 'bar', 'baz'))
        );
    }

}
