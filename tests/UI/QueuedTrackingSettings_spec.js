/*!
 * Matomo - free/libre analytics platform
 *
 * Screenshot integration tests.
 *
 * @link https://matomo.org
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

    it("should display the settings page", async function () {
        await page.goto(url);
        await page.mouse.move(-10, -10);
        expect(await page.screenshotSelector(selector)).to.matchImage('settings_page');
    });

    it("should show an error if queue is enabled and redis connection is wrong", async function () {
        await page.click('#queueEnabled + span');
        await page.type('input[name="redisPort"]', '1');
        await (await page.jQuery('.card-content:contains(\'QueuedTracking\') .pluginsSettingsSubmit')).click();
        await page.type('.confirm-password-modal input[type=password]', 'superUserPass');
        await page.click('.confirm-password-modal .modal-close.btn');
        await page.waitForNetworkIdle();
        // hide all cards, except of QueueTracking
        await page.evaluate(function(){
            $('.card-content').hide();
            $('.card-content:contains(\'QueuedTracking\')').show();
        });
        await page.mouse.move(-10, -10);
        expect(await page.screenshotSelector(selector + ',#ajaxError,#notificationContainer')).to.matchImage('settings_save_error');
    });

    it("should display the settings page with sentinel enabled", async function () {

        testEnvironment.overrideConfig('QueuedTracking', {
            useSentinelBackend: '1'
        });
        testEnvironment.save();

        await page.goto(url);
        await page.mouse.move(-10, -10);
        expect(await page.screenshotSelector(selector)).to.matchImage('settings_page_sentinel');
    });

});
