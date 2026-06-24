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
        // Scroll the toggle to the viewport centre before clicking: under the modern headless Chrome
        // it can otherwise be reported as "not clickable" when it sits behind a sticky header.
        const queueEnabledToggle = await page.waitForSelector('#queueEnabled + span');
        await queueEnabledToggle.evaluate(el => el.scrollIntoView({ block: 'center' }));
        // Use a JS click rather than page.click(): under the modern headless Chrome the toggle's
        // lever can be reported as "not clickable" (covered by a sticky header / zero hit area)
        // even after scrolling, while the click event itself fires the handler fine.
        await queueEnabledToggle.evaluate(el => el.click());
        await page.type('input[name="redisPort"]', '1');

        // Use a JS click for the submit button: page/element click can be reported as "not
        // clickable" under the modern headless Chrome, so the password-confirmation modal never
        // opened.
        const submitButton = await page.jQuery('.card-content:contains(\'QueuedTracking\') .pluginsSettingsSubmit');
        await submitButton.evaluate(el => el.click());

        // Wait for the password-confirmation modal to finish opening before interacting with it:
        // under the modern headless Chrome the input is not yet focusable right after the submit
        // click, and the confirm button can be reported as "not clickable" mid-animation.
        const passwordInput = await page.waitForSelector('.confirm-password-modal input[type=password]', { visible: true });
        await passwordInput.type(superUserPassword);
        const confirmButton = await page.waitForSelector('.confirm-password-modal .modal-close.btn', { visible: true });
        await confirmButton.evaluate(el => el.click());

        await page.waitForNetworkIdle();
        // hide all cards, except of QueueTracking
        await page.evaluate(function(){
            $('.card').hide();
            $('.card:contains(\'QueuedTracking\')').show();
            $('.card:contains(\'Queued Tracking\')').show();
        });
        await page.mouse.move(-10, -10);
        expect(await page.screenshotSelector(selector + ',#ajaxError,#notificationContainer')).to.matchImage('settings_save_error');
    });

    it("should display the settings page with sentinel enabled", async function () {

        testEnvironment.overrideConfig('QueuedTracking', {
            useWhatRedisBackendType: '2'
        });
        testEnvironment.save();

        await page.goto(url);
        await page.mouse.move(-10, -10);
        expect(await page.screenshotSelector(selector)).to.matchImage('settings_page_sentinel');
    });

});
