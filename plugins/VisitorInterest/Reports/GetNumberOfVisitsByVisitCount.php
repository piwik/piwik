<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\VisitorInterest\Reports;

use Piwik\Metrics;
use Piwik\Piwik;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\VisitorInterest\Columns\VisitsbyVisitNumber;
use Piwik\Plugins\CoreHome\Columns\Metrics\VisitsPercent;

class GetNumberOfVisitsByVisitCount extends Base
{
    protected $defaultSortColumn = '';

    protected function init()
    {
        parent::init();
        $this->dimension     = new VisitsbyVisitNumber();
        $this->name          = Piwik::translate('VisitorInterest_visitsByVisitCount');
        $this->documentation = Piwik::translate('VisitorInterest_WidgetVisitsByNumDocumentation')
                             . '<br />' . Piwik::translate('General_ChangeTagCloudView');
        $this->metrics       = array('nb_visits');
        $this->processedMetrics  = array(
            new VisitsPercent()
        );
        $this->constantRowsCount = true;
        $this->order = 25;
    }

    public function configureView(ViewDataTable $view)
    {
        $view->requestConfig->filter_sort_column = 'label';
        $view->requestConfig->filter_sort_order  = 'asc';
        $view->requestConfig->filter_limit = 15;

        $view->config->addTranslations(array(
            'label'                => Piwik::translate('VisitorInterest_VisitNum'),
            'nb_visits_percentage' => Metrics::getPercentVisitColumn())
        );

        $view->config->columns_to_display = array('label', 'nb_visits', 'nb_visits_percentage');
        $view->config->show_exclude_low_population = false;

        $view->config->enable_sort = false;
        $view->config->show_offset_information = false;
        $view->config->show_pagination_control = false;
        $view->config->show_limit_control      = false;
        $view->config->show_search             = false;
        $view->config->show_table_all_columns  = false;
        $view->config->show_all_views_icons    = false;
    }

    public function isRequiresProfilableData()
    {
        return true;
    }
}
