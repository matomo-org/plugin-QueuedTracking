/*!
 * Piwik - free/libre analytics platform
 *
 * Screenshot integration tests.
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

describe("QueuedTrackingSettings", function () {
    this.timeout(0);

    var selector = '.card-content:contains(\'QueuedTracking\')';
    var url = "?module=CoreAdminHome&action=generalSettings&idSite=1&period=day&date=yesterday";

    beforeEach(function () {
        if (testEnvironment.configOverride.QueuedTracking) {
            delete testEnvironment.configOverride.QueuedTracking;
        }
        testEnvironment.save();
    });

    after(function () {
        if (testEnvironment.configOverride.QueuedTracking) {
            delete testEnvironment.configOverride.QueuedTracking;
        }
        testEnvironment.save();
    });

    it("should display the settings page", function (done) {
        expect.screenshot('settings_page').to.be.captureSelector(selector, function (page) {
            page.load(url);
        }, done);
    });

    it("should show an error if queue is enabled and redis connection is wrong", function (done) {
        expect.screenshot('settings_save_error').to.be.captureSelector(selector + ',#notificationContainer', function (page) {
            page.click('label[for=queueEnabled]');
            page.sendKeys('input[name=redisPort]', '1');
            page.click('.card-content:contains(\'QueuedTracking\') .pluginsSettingsSubmit');
            page.wait(750);
            // hide all cards, except of QueueTracking
            page.evaluate(function(){
                $('.card-content').hide();
                $('.card-content:contains(\'QueuedTracking\')').show();
            });
        }, done);
    });

    it("should display the settings page with sentinel enabled", function (done) {

        testEnvironment.overrideConfig('QueuedTracking', {
            useSentinelBackend: '1'
        });
        testEnvironment.save();

        expect.screenshot('settings_page_sentinel').to.be.captureSelector(selector, function (page) {
            page.load(url);
        }, done);
    });

});