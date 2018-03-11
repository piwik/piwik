<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\PrivacyManager;

use Piwik\API\Request;
use Piwik\Columns\Dimension;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Mail;
use Piwik\Metrics\Formatter;
use Piwik\Piwik;
use Piwik\Config as PiwikConfig;
use Piwik\Plugins\PrivacyManager\Model\Gdpr;
use Piwik\Segment;
use Piwik\Tracker\LogTable;

/**
 * API for plugin PrivacyManager
 *
 * @method static \Piwik\Plugins\PrivacyManager\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /**
     * @var Gdpr
     */
    private $gdpr;

    public function __construct(Gdpr $gdpr)
    {
        $this->gdpr = $gdpr;
    }

    public function deleteDataSubjects($visits)
    {
        Piwik::checkUserHasSomeAdminAccess();

        $idSites = array();
        foreach ($visits as $visit) {
            $idSites[] = $visit['idSite'];
        }
        Piwik::checkUserHasAdminAccess($idSites);

        return $this->gdpr->deleteDataSubjects($visits);
    }

    public function emailDataSubjectExport($visits, $emailAddress, $subject, $message)
    {
        Piwik::checkUserHasSomeAdminAccess();

        if (!Piwik::isValidEmailString($emailAddress)) {
            throw new \Exception('Invalid email address');
        }

        $result = Request::processRequest('PrivacyManager.exportDataSubjects',
            array(
            'format' => 'json',
            'visits' => $visits,
            'filter_limit' => '-1')
        );
        if (empty($result)) {
            throw new \Exception('No data was exported');
        }

        $mail = new Mail();
        $mail->setDefaultFromPiwik();
        $mail->addTo($emailAddress);
        $mail->setSubject($subject);
        $mail->setBodyText($message);
        $mail->addAttachment($mail->createAttachment($result,
            'application/json',
            \Zend_Mime::DISPOSITION_ATTACHMENT,
            \Zend_Mime::ENCODING_BASE64,
            'export.json'));
        $mail->send();

        return $result;
    }

    public function exportDataSubjects($visits)
    {
        Piwik::checkUserHasSomeAdminAccess();

        $idSites = array();
        foreach ($visits as $visit) {
            $idSites[] = $visit['idSite'];
        }
        Piwik::checkUserHasAdminAccess($idSites);

        return $this->gdpr->exportDataSubjects($visits);
    }

    /**
     * @internal
     */
    public function setAnonymizeIpSettings($anonymizeIPEnable, $maskLength, $useAnonymizedIpForVisitEnrichment)
    {
        Piwik::checkUserHasSuperUserAccess();

        if ($anonymizeIPEnable == '1') {
            IPAnonymizer::activate();
        } else if ($anonymizeIPEnable == '0') {
            IPAnonymizer::deactivate();
        } else {
            // pass
        }

        $privacyConfig = new Config();
        $privacyConfig->ipAddressMaskLength = (int) $maskLength;
        $privacyConfig->useAnonymizedIpForVisitEnrichment = (bool) $useAnonymizedIpForVisitEnrichment;

        return true;
    }

    /**
     * @internal
     */
    public function deactivateDoNotTrack()
    {
        Piwik::checkUserHasSuperUserAccess();

        $dntChecker = new DoNotTrackHeaderChecker();
        $dntChecker->deactivate();

        return true;
    }

    /**
     * @internal
     */
    public function activateDoNotTrack()
    {
        Piwik::checkUserHasSuperUserAccess();

        $dntChecker = new DoNotTrackHeaderChecker();
        $dntChecker->activate();

        return true;
    }

    /**
     * @internal
     */
    public function setScheduleReportDeletionSettings($deleteLowestInterval = 7)
    {
        return $this->savePurgeDataSettings(array(
            'delete_logs_schedule_lowest_interval' => (int) $deleteLowestInterval
        ));
    }

    /**
     * @internal
     */
    public function setDeleteLogsSettings($enableDeleteLogs = '0', $deleteLogsOlderThan = 180)
    {
        $deleteLogsOlderThan = (int) $deleteLogsOlderThan;
        if ($deleteLogsOlderThan < 1) {
            $deleteLogsOlderThan = 1;
        }

        return $this->savePurgeDataSettings(array(
            'delete_logs_enable' => !empty($enableDeleteLogs),
            'delete_logs_older_than' => $deleteLogsOlderThan,
        ));
    }

    /**
     * @internal
     */
    public function setDeleteReportsSettings($enableDeleteReports = 0, $deleteReportsOlderThan = 3,
                                             $keepBasic = 0, $keepDay = 0, $keepWeek = 0, $keepMonth = 0,
                                             $keepYear = 0, $keepRange = 0, $keepSegments = 0)
    {
        $settings = array();

        // delete reports settings
        $settings['delete_reports_enable'] = !empty($enableDeleteReports);

        $deleteReportsOlderThan = (int) $deleteReportsOlderThan;
        if ($deleteReportsOlderThan < 3) {
            $deleteReportsOlderThan = 3;
        }

        $settings['delete_reports_older_than'] = $deleteReportsOlderThan;

        $settings['delete_reports_keep_basic_metrics']   = (int) $keepBasic;
        $settings['delete_reports_keep_day_reports']     = (int) $keepDay;
        $settings['delete_reports_keep_week_reports']    = (int) $keepWeek;
        $settings['delete_reports_keep_month_reports']   = (int) $keepMonth;
        $settings['delete_reports_keep_year_reports']    = (int) $keepYear;
        $settings['delete_reports_keep_range_reports']   = (int) $keepRange;
        $settings['delete_reports_keep_segment_reports'] = (int) $keepSegments;
        $settings['delete_logs_max_rows_per_query']      = PiwikConfig::getInstance()->Deletelogs['delete_logs_max_rows_per_query'];

        return $this->savePurgeDataSettings($settings);
    }

    private function savePurgeDataSettings($settings)
    {
        Piwik::checkUserHasSuperUserAccess();

        $this->checkDataPurgeAdminSettingsIsEnabled();

        PrivacyManager::savePurgeDataSettings($settings);

        return true;
    }
    
    private function checkDataPurgeAdminSettingsIsEnabled()
    {
        if (!Controller::isDataPurgeSettingsEnabled()) {
            throw new \Exception("Configuring deleting log data and report data has been disabled by Matomo admins.");
        }
    }
}
