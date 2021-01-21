/*!
 * Matomo - free/libre analytics platform
 *
 * OneClickUpdate screenshot tests.
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

describe("NoprofilableData", function () {
    this.fixture = 'Piwik\\Tests\\Fixtures\\NonProfilableData';

    this.timeout(0);

    var generalParams = 'idSite=1&period=day&date=2020-04-04',
        urlBaseGeneric = 'module=CoreHome&action=index&',
        urlBase = urlBaseGeneric + generalParams,
        url = "?" + urlBase,
        engagementUrl = url + '#?' + generalParams + '&category=General_Actions&subcategory=VisitorInterest_Engagement',
        goalsUrl = url + '#?' + generalParams + '&category=Goals_Goals&subcategory=1',
        visitsLogUrl = url + '#?' + generalParams + '&category=General_Visitors&subcategory=Live_VisitorLog',
        visitsOverviewUrl = url + '#?' + generalParams + '&category=General_Visitors&subcategory=General_Overview',
        devicesUrl = url + '#?' + generalParams + '&category=General_Visitors&subcategory=DevicesDetection_Devices'
    ;

    it('should not show segments that require profilable data', async () => {
        await page.goto(url);
        await page.click('.segmentationContainer .title');
        await page.waitFor(200);
        await page.click('.add_new_segment');
        await page.waitForNetworkIdle();
        await page.click('.metricListBlock');
        await (await page.jQuery('.expandableListCategory:contains(Visitors)')).click();

        const visitorIdCount = await page.evaluate(() => $('.expandableListItem:contains(Visitor ID)').length);
        expect(visitorIdCount).to.equal(0);

        expect(await page.screenshotSelector('.metricListBlock .expandableList')).to.matchImage('no_profilable_segments');
    });

    it('should not show reports that require profilable data', async () => {
        await page.click('.segmentationContainer');
        await page.waitFor(100);
        await page.click('.segmentationContainer'); // second click to close segment editor

        await page.goto(engagementUrl);

        const reportCount = await page.evaluate(() => $('#widgetVisitorInterestgetNumberOfVisitsByDaysSinceLast').length);
        expect(reportCount).to.equal(0);
    });

    it('should not show disabled reports in report by dimension views when no profilable data', async () => {
        await page.goto(goalsUrl);
        await page.waitForNetworkIdle();
        await page.waitFor('.reportsByDimensionView');

        expect(await page.screenshotSelector('.reportsByDimensionView > .entityList')).to.matchImage('reports_by_dimension');
    });

    it('should not show unique visitors w/o profilable data', async () => {
        await page.goto(devicesUrl);

        expect(await page.screenshotSelector('.reporting-page')).to.matchImage('no_unique_visitors');
    });

    it('should not show dimensions requiring profilable data in visitor log', async () => {
        await page.goto(visitsLogUrl);

        expect(await page.screenshotSelector('.dataTableVizVisitorLog')).to.matchImage('visits_log');
    });

    // TODO: what about nb_uniq_visitors in evolution graph? double check.
    // TODO: what about other uniq visitor based metrics in sparklines and evolution graphs? check that too.

    it('should not show nb_uniq_visitors metrics in sparklines', async () => {
        await page.goto(visitsOverviewUrl);
        await page.waitForNetworkIdle();

        expect(await page.screenshotSelector('#widgetVisitsSummarygetforceView1viewDataTablesparklines')).to.matchImage('visits_log');
    });
});
