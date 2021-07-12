/*!
 * Matomo - free/libre analytics platform
 *
 * Screenshot integration tests.
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

describe("Goals", function () {

    var generalParams = 'idSite=1&period=year&date=2012-08-09',
        urlBase = 'module=CoreHome&action=index&' + generalParams;

        // goal management
    it('should load the goals > management page correctly', async function () {
        await page.goto("?" + generalParams + "&module=Goals&action=manage");
        await page.waitForNetworkIdle();

        expect(await page.screenshotSelector('#content,.top_bar_sites_selector,.entityContainer')).to.matchImage('manage');
    });

    it('should show the form to add a goal', async function () {
        await page.click('#add-goal');

        expect(await page.screenshotSelector('#content')).to.matchImage('add');
    });

    it('should be possible to fill the goal form', async function () {
        await page.type('#goal_name', 'new goal');
        await page.type('#goal_description', 'new goal description');
        await page.click('#match_attributevisit_nb_pageviews');
        await page.type('#pattern', '4');

        expect(await page.screenshotSelector('#content')).to.matchImage('add_filled');
    });

    it('should add the goal when submitting the form', async function () {
        await page.click('[piwik-save-button]');
        await page.waitForNetworkIdle();

        expect(await page.screenshotSelector('#content')).to.matchImage('added');
    });

    it('should show confirmation when removing a goal', async function () {
        await page.click('tr:last-child .icon-delete');
        await page.waitFor(500);
        await page.mouse.move(0, 0);

        var modal = await page.$('.modal.open');
        expect(await modal.screenshot()).to.matchImage('delete_confirm');
    });

    it('should remove goal on confirmation', async function () {
        await page.click('.modal-action:first-child');
        await page.waitForNetworkIdle();
        await page.mouse.move(0, 0);

        expect(await page.screenshotSelector('#content')).to.matchImage('deleted');
    });

    // goals pages
    it('should load the goals > ecommerce page correctly', async function () {
        await page.goto("?" + urlBase + "#?" + generalParams + "&category=Goals_Ecommerce&subcategory=General_Overview")
        await page.waitForNetworkIdle();

        expect(await page.screenshotSelector('.pageWrap')).to.matchImage('ecommerce');
    });

    it('should load the goals > overview page correctly', async function () {
        await page.goto("?" + urlBase + "#?" + generalParams + "&category=Goals_Goals&subcategory=General_Overview");
        await page.waitForNetworkIdle();

        expect(await page.screenshotSelector('.pageWrap')).to.matchImage('overview');
    });

    it('should load the goals > single goal page correctly', async function () {
        await page.goto("?" + urlBase + "#?" + generalParams + "&category=Goals_Goals&subcategory=1");
        await page.waitForNetworkIdle();

        expect(await page.screenshotSelector('.pageWrap')).to.matchImage('individual_goal');
    });

    it('should update the evolution chart if a sparkline is clicked', async function () {
        elem = await page.jQuery('.sparkline.linked:contains(%)');
        await elem.click();
        await page.waitForNetworkIdle();
        await page.mouse.move(-10, -10);

        expect(await page.screenshotSelector('.pageWrap')).to.matchImage('individual_goal_updated');
    });

    // should load the row evolution [see #11526]
    it('should show row evolution for goal tables', async function () {
        await page.waitForNetworkIdle();

        const row = await page.waitForSelector('.dataTable tbody tr:first-child');
        await row.hover();

        const icon = await page.waitForSelector('.dataTable tbody tr:first-child a.actionRowEvolution');
        await icon.click();

        await page.waitForSelector('.rowevolution');
        await page.waitForNetworkIdle();

        expect(await page.screenshotSelector('.ui-dialog')).to.matchImage('individual_row_evolution');
    });
});
