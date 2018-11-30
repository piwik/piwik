<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Live\Reports;

use Piwik\Common;
use Piwik\DataTable;
use Piwik\Piwik;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\CoreHome\Columns\VisitorId;
use Piwik\Plugins\CoreVisualizations\Visualizations\HtmlTable;
use Piwik\Plugins\CoreVisualizations\Visualizations\HtmlTable\AllColumns;
use Piwik\Plugins\Live\Columns\Metrics\AverageTimeSpent;
use Piwik\Plugins\Live\Columns\Metrics\TotalTimeSpent;

class GetVisitors extends \Piwik\Plugin\Report
{
    protected function init()
    {
        parent::init();
        $this->order = 5;
        $this->categoryId = 'General_Profiles';
        $this->subcategoryId = 'General_Visitors';
        $this->name = 'General_Visitors';
        $this->metrics = array('user_id', 'nb_visits', 'nb_conversions', 'nb_actions');
        $this->defaultSortColumn = 'nb_visits';
        $this->processedMetrics = array(
            new TotalTimeSpent(),
            new AverageTimeSpent()
        );
        $this->dimension = new VisitorId();
    }

    public function getDefaultTypeViewDataTable()
    {
        return AllColumns::ID;
    }

    public function configureView(ViewDataTable $view)
    {
        if (!empty($this->dimension)) {
            $view->config->addTranslations(array('label' => $this->dimension->getName()));
        }
        $view->config->addTranslation('sum_time_spent', Piwik::translate('General_ColumnSumTimeOnSite'));
        $view->config->addTranslation('user_id', Piwik::translate('General_UserId'));

        if ($view->isViewDataTableId(AllColumns::ID)) {
            $view->config->columns_to_display = array_merge(
                array('label'),
                array_keys($this->getMetrics()),
                array_keys($this->getProcessedMetrics())
            );
        } else {
            $view->config->columns_to_display = array('label', 'nb_visits');
        }
        $view->config->show_related_reports = false;
        $view->config->show_insights = false;
        $view->config->show_pivot_by_subtable = false;
        $view->config->show_table = true;
        $view->config->show_table_all_columns = true;
        $view->config->show_bar_chart = false;
        $view->config->show_pie_chart = false;
        $view->config->show_tag_cloud = false;
        $view->config->show_offset_information = false;

        // disabled cause we filter in the API itself... we can probably enable this later by adding support in the API for this
        $view->config->show_search = false;

        if ($view->isViewDataTableId(HtmlTable::ID)) {
            // it should work in general but would query the data live for each day/period
            $view->config->disable_row_evolution = true;
        }

        $view->config->filters[] = function (DataTable $table) use ($view) {

            if ($view->isViewDataTableId(AllColumns::ID)) {
                $view->config->columns_to_display = array_merge(
                    array('label'),
                    array_keys($this->getMetrics()),
                    array_keys($this->getProcessedMetrics())
                );
            } else {
                $view->config->columns_to_display = array('label', 'nb_visits');
            }

            if ($table->getMetadata('hasMoreVisits')) {
                $table->setMetadata(DataTable::TOTAL_ROWS_BEFORE_LIMIT_METADATA_NAME, 9999);
            }
        };

        // exclude visitors with less then 2 visits, when low population filter is active
        $view->requestConfig->filter_excludelowpop_value = 2;
    }

}
