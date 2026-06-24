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
        // Toggle the checkbox input directly. page.click on the visible label/span is reported as
        // "not clickable" under the modern headless Chrome, and a synthetic click on the label span
        // does not reliably flip the underlying checkbox - so the setting never changed and saving
        // did not prompt for the password. Clicking the input fires its change handler so the value
        // actually changes.
        const queueEnabledInput = await page.waitForSelector('#queueEnabled');
        await queueEnabledInput.evaluate(el => el.click());
        await page.type('input[name="redisPort"]', '1');

        // Use a JS click for the submit button: page/element click can be reported as "not
        // clickable" under the modern headless Chrome, so the password-confirmation modal never
        // opened.
        const submitButton = await page.jQuery('.card-content:contains(\'QueuedTracking\') .pluginsSettingsSubmit');
        await submitButton.evaluate(el => el.click());

        // Saving opens the password-confirmation modal. Scope every selector to the modal that is
        // actually open: the settings page renders several .confirm-password-modal instances, and an
        // unscoped selector matches a closed one whose password field never becomes visible. Wait for
        // the field, enter the password and confirm (JS click - the button can be reported as "not
        // clickable" mid-animation under the modern headless Chrome).
        await page.waitForSelector('.confirm-password-modal.modal.open #currentUserPassword', { visible: true });
        await page.waitForTimeout(250);
        await page.type('.confirm-password-modal.modal.open #currentUserPassword', superUserPassword);
        const confirmButton = await page.waitForSelector('.confirm-password-modal.modal.open .confirm-password-btn', { visible: true });
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
