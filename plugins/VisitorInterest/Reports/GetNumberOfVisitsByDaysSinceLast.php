<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\VisitorInterest\Reports;

use Piwik\Piwik;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\VisitorInterest\Columns\VisitorDaysSinceLast;
use Piwik\Report\ReportWidgetFactory;
use Piwik\Widget\WidgetsList;

class GetNumberOfVisitsByDaysSinceLast extends Base
{
    protected $defaultSortColumn = '';

    protected function init()
    {
        parent::init();
        $this->dimension     = new VisitorDaysSinceLast();
        $this->name          = Piwik::translate('VisitorInterest_VisitsByDaysSinceLast');
        $this->documentation = Piwik::translate('VisitorInterest_WidgetVisitsByDaysSinceLastDocumentation');
        $this->metrics       = array('nb_visits');
        $this->processedMetrics  = false;
        $this->constantRowsCount = true;
        $this->order = 30;

        $this->subcategoryId = 'VisitorInterest_Engagement';
    }

    public function configureWidgets(WidgetsList $widgetsList, ReportWidgetFactory $factory)
    {
        $widget = $factory->createWidget()->setName('VisitorInterest_WidgetVisitsByDaysSinceLast');
        $widgetsList->addWidgetConfig($widget);
    }

    public function configureView(ViewDataTable $view)
    {
        $view->requestConfig->filter_sort_column = 'label';
        $view->requestConfig->filter_sort_order  = 'asc';
        $view->requestConfig->filter_limit = 15;

        $view->config->show_search = false;
        $view->config->enable_sort = false;
        $view->config->show_offset_information = false;
        $view->config->show_pagination_control = false;
        $view->config->show_limit_control      = false;
        $view->config->show_all_views_icons    = false;
        $view->config->show_table_all_columns  = false;
        $view->config->show_exclude_low_population = false;
        $view->config->addTranslation('label', Piwik::translate('General_DaysSinceLastVisit'));
    }

    public function isRequiresProfilableData()
    {
        return true;
    }
}
