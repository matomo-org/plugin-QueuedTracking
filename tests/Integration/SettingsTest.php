<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration;

use Piwik\Plugins\QueuedTracking\Settings;
use Piwik\Plugins\QueuedTracking\tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group QueuedTracking
 * @group SettingsTest
 * @group Plugins
 * @group Tracker
 */
class SettingsTest extends IntegrationTestCase
{
    /**
     * @var Settings
     */
    private $settings;

    public function setUp()
    {
        parent::setUp();

        $this->settings = new Settings();
    }

    public function tearDown()
    {
        parent::tearDown();
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Max 500 characters
     */
    public function test_redisHost_ShouldFail_IfMoreThan300CharctersGiven()
    {
        $this->settings->redisHost->setValue(str_pad('3', 503, '4'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Port has to be at least 1
     */
    public function test_redisPort_ShouldFail_IfPortIsTooLow()
    {
        $this->settings->redisPort->setValue(0);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Port should be max 65535
     */
    public function test_redisPort_ShouldFail_IfPortIsTooHigh()
    {
        $this->settings->redisPort->setValue(65536);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Max 5 characters
     */
    public function test_redisTimeout_ShouldFail_IfTooLong()
    {
        $this->settings->redisTimeout->setValue('333.43');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage should be numeric
     */
    public function test_redisTimeout_ShouldFail_IfNotNumeric()
    {
        $this->settings->redisTimeout->setValue('33d3.43');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Max 200 characters are allowed
     */
    public function test_sentinelMasterName_ShouldFail_IfTooManyCharacters()
    {
        $this->settings->sentinelMasterName->setValue(str_pad('1', 201, '1'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Max 100 characters
     */
    public function test_redisPassword_ShouldFail_IfMoreThan100CharctersGiven()
    {
        $this->settings->redisPassword->setValue(str_pad('4', 102, '4'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Connection to Redis failed
     */
    public function test_queueEnabled_ShouldFail_IfEnabledButWrongConnectionDetail()
    {
        $this->settings->redisPort->setValue(6378);
        $this->settings->queueEnabled->setValue(true);
    }

    public function test_queueEnabled_ShouldNotFail_IfEnabledButWrongConnectionDetail()
    {
        $this->settings->redisPort->setValue(6378);
        $this->settings->queueEnabled->setValue(false);

        $this->assertFalse($this->settings->queueEnabled->getValue());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Number should be 1 or higher
     */
    public function test_numRequestsToProcess_ShouldFail_IfTooLow()
    {
        $this->settings->numRequestsToProcess->setValue(0);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Value should be a number
     */
    public function test_numRequestsToProcess_ShouldFail_IfNotNumeric()
    {
        $this->settings->numRequestsToProcess->setValue('33d3.43');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage The database has to be an integer
     */
    public function test_redisDatabase_ShouldFail_IfIsNumericButFloat()
    {
        $this->settings->redisDatabase->setValue('5.34');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage The database has to be an integer
     */
    public function test_redisDatabase_ShouldFail_IfNotNumeric()
    {
        $this->settings->redisDatabase->setValue('33d3.43');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Max 5 digits allowed
     */
    public function test_redisDatabase_ShouldFail_IfTooLong()
    {
        $this->settings->redisDatabase->setValue('333333');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage should be an integer
     */
    public function test_numQueueWorkers_ShouldFail_IfNotNumeric()
    {
        $this->settings->numQueueWorkers->setValue('1f');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Only 1-16 workers allowed
     */
    public function test_numQueueWorkers_ShouldFail_IfTooHigh()
    {
        $this->settings->numQueueWorkers->setValue('17');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Only 1-16 workers allowed
     */
    public function test_numQueueWorkers_ShouldFail_IfTooLow()
    {
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
        $this->settings->redisTimeout->setValue('4.45');
        $this->assertSame(4.45, $this->settings->redisTimeout->getValue());
    }

    public function test_redisPort_ShouldConvertAValueToIntButTypeString()
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

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage QueuedTracking_MultipleServersOnlyConfigurableIfSentinelEnabled
     */
    public function test_redisPort_ShouldFailWhenMultipleValuesGiven_IfSentinelNotEnabled()
    {
        $this->settings->redisPort->setValue('45,56,788');
    }

    public function test_redisPort_ShouldNotFailAndConvertToIntWhenMultipleValuesGiven_IfSentinelIsEnabled()
    {
        $this->enableRedisSentinel();
        $this->settings->redisPort->setValue('55 , 44.34 ');
        $this->assertSame('55,44', $this->settings->redisPort->getValue());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage A port has to be a number
     */
    public function test_redisPort_ShouldValidateEachPortSeparately_WhenManySpecified()
    {
        $this->enableRedisSentinel();
        $this->settings->redisPort->setValue('55 , 44.34, 4mk ');
        $this->assertSame('55,44', $this->settings->redisPort->getValue());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage QueuedTracking_MultipleServersOnlyConfigurableIfSentinelEnabled
     */
    public function test_redisHost_ShouldFailWhenMultipleValuesGiven_IfSentinelNotEnabled()
    {
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
        $this->settings->processDuringTrackingRequest->setValue('y');
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
     * @expectedException \Exception
     * @expectedExceptionMessage QueuedTracking_MultipleServersOnlyConfigurableIfSentinelEnabled
     */
    public function test_checkMultipleServersOnlyConfiguredWhenSentinelIsEnabled_shouldFailWhenMoreThanOneValue_IfSentinelNotEnabled($stringValue)
    {
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

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage QueuedTracking_NumHostsNotMatchNumPorts
     */
    public function test_save_shouldFailIfPortAndHostMismatch()
    {
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
