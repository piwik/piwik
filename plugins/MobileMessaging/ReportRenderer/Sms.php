<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\MobileMessaging\ReportRenderer;

use Piwik\Common;
use Piwik\Plugins\MultiSites\API;
use Piwik\ReportRenderer;
use Piwik\Site;
use Piwik\View;

/**
 *
 */
class Sms extends ReportRenderer
{
    const FLOAT_REGEXP = '/[-+]?[0-9]*[\.,]?[0-9]+/';
    const SMS_CONTENT_TYPE = 'text/plain';
    const SMS_FILE_EXTENSION = 'sms';

    private $rendering = "";

    public function setLocale($locale)
    {
        // nothing to do
    }

    public function sendToDisk($filename)
    {
        return ReportRenderer::writeFile($filename, self::SMS_FILE_EXTENSION, $this->rendering);
    }

    public function sendToBrowserDownload($filename)
    {
        ReportRenderer::sendToBrowser($filename, self::SMS_FILE_EXTENSION, self::SMS_CONTENT_TYPE, $this->rendering);
    }

    public function sendToBrowserInline($filename)
    {
        ReportRenderer::inlineToBrowser(self::SMS_CONTENT_TYPE, $this->rendering);
    }

    public function getRenderedReport()
    {
        return $this->rendering;
    }

    public function renderFrontPage($reportTitle, $prettyDate, $description, $reportMetadata, $segment)
    {
        // nothing to do
    }

    public function renderReport($processedReport)
    {
        $isGoalPluginEnabled = Common::isGoalPluginEnabled();
        $prettyDate = $processedReport['prettyDate'];
        $reportData = $processedReport['reportData'];

        $evolutionMetrics = array();
        $multiSitesAPIMetrics = API::getApiMetrics($enhanced = true);
        foreach ($multiSitesAPIMetrics as $metricSettings) {
            $evolutionMetrics[] = $metricSettings[API::METRIC_EVOLUTION_COL_NAME_KEY];
        }

        $floatRegex = self::FLOAT_REGEXP;
        // no decimal for all metrics to shorten SMS content (keeps the monetary sign for revenue metrics)
        $reportData->filter(
            'ColumnCallbackReplace',
            array(
                 array_merge(array_keys($multiSitesAPIMetrics), $evolutionMetrics),
                 function ($value) use ($floatRegex) {
                     return preg_replace_callback(
                         $floatRegex,
                         function ($matches) {
                             return round((float) $matches[0]);
                         },
                         $value
                     );
                 }
            )
        );

        // evolution metrics formatting :
        //  - remove monetary, percentage and white spaces to shorten SMS content
        //    (this is also needed to be able to test $value != 0 and see if there is an evolution at all in SMSReport.twig)
        $reportData->filter(
            'ColumnCallbackReplace',
            array(
                 $evolutionMetrics,
                 function ($value) use ($floatRegex) {
                     $matched = preg_match($floatRegex, $value, $matches);
                     return $matched ? (float) $matches[0] : $value;
                 }
            )
        );

        $dataRows = $reportData->getRows();
        $reportMetadata = $processedReport['reportMetadata'];
        $reportRowsMetadata = $reportMetadata->getRows();

        $siteHasECommerce = array();
        foreach ($reportRowsMetadata as $rowMetadata) {
            $idSite = $rowMetadata->getColumn('idsite');
            $siteHasECommerce[$idSite] = Site::isEcommerceEnabledFor($idSite);
        }

        $view = new View('@MobileMessaging/SMSReport');
        $view->assign("isGoalPluginEnabled", $isGoalPluginEnabled);
        $view->assign("reportRows", $dataRows);
        $view->assign("reportRowsMetadata", $reportRowsMetadata);
        $view->assign("prettyDate", $prettyDate);
        $view->assign("siteHasECommerce", $siteHasECommerce);
        $view->assign("displaySiteName", $processedReport['metadata']['action'] == 'getAll');

        // segment
        $segment = $processedReport['segment'];
        $displaySegment = ($segment != null);
        $view->assign("displaySegment", $displaySegment);
        if ($displaySegment) {
            $view->assign("segmentName", $segment['name']);
        }

        $this->rendering .= $view->render();
    }

    /**
     * Get report attachments, ex. graph images
     *
     * @param $report
     * @param $processedReports
     * @param $prettyDate
     * @return array
     */
    public function getAttachments($report, $processedReports, $prettyDate)
    {
        return array();
    }
}
