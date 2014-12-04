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

    var selector = '#QueuedTracking,#QueuedTracking + .pluginIntroduction,#QueuedTracking + .pluginIntroduction + .adminTable';
    var url = "?module=CoreAdminHome&action=pluginSettings&idSite=1&period=day&date=yesterday";

    it("should display the settings page", function (done) {
        expect.screenshot('settings_page').to.be.captureSelector(selector, function (page) {
            page.load(url);
        }, done);
    });

    it("should show an error if queue is enabled and redis connection is wrong", function (done) {
        expect.screenshot('settings_save_error').to.be.captureSelector(selector + ',#ajaxErrorPluginSettings', function (page) {
            page.click('input[name=queueEnabled]');
            page.sendKeys('input[name=redisPort]', '1');
            page.click('.pluginsSettingsSubmit');
            page.wait(1000);
        }, done);
    });
});