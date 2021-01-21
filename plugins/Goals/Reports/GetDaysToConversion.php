<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Goals\Reports;

use Piwik\Piwik;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\Goals\Columns\DaysToConversion;
use Piwik\Plugins\Goals\Archiver;

class GetDaysToConversion extends Base
{
    protected $defaultSortColumn = '';

    protected function init()
    {
        parent::init();

        $this->name = Piwik::translate('Goals_DaysToConv');
        $this->documentation = Piwik::translate('Goals_DaysToConvReportDocumentation');
        $this->dimension = new DaysToConversion();
        $this->constantRowsCount = true;
        $this->processedMetrics = false;
        $this->parameters = array();

        $this->metrics = array('nb_conversions');
        $this->order = 10;
        $this->orderGoal = 52;
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->show_search = false;
        $view->config->show_exclude_low_population = false;
        $view->config->show_table_all_columns  = false;
        $view->config->show_all_views_icons  = false;
        $view->config->show_offset_information = false;
        $view->config->show_pagination_control = false;
        $view->config->columns_to_display      = array('label', 'nb_conversions');

        $view->requestConfig->filter_sort_column = 'label';
        $view->requestConfig->filter_sort_order  = 'asc';
        $view->requestConfig->filter_limit       = count(Archiver::$daysToConvRanges);

        $view->config->addTranslations(array('label' => $this->dimension->getName()));
    }

    public function configureReportMetadata(&$availableReports, $infos)
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (null !== $this->getIdSiteFromInfos($infos)) {
            parent::configureReportMetadata($availableReports, $infos);
        }

        $name = $this->name;

        $this->addReportMetadataForEachGoal($availableReports, $infos, function ($goal) use ($name) {
            return $goal['name'] . ' - ' . $name;
        });
    }

    public function isRequiresProfilableData()
    {
        return true;
    }
}
