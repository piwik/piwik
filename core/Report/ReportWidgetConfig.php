<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Report;
use Piwik\Plugin\ReportsProvider;
use Piwik\Widget\WidgetConfig;

/**
 * Defines a widget config that is used to render a report.
 *
 * @api since Piwik 3.0.0
 */
class ReportWidgetConfig extends WidgetConfig
{
    protected $viewDataTable = null;
    protected $forceViewDataTable = false;

    /**
     * Sets a default viewDataTable that should be used to render the report. This is not necessarily the
     * view that will be actually used to render the report. Eg if a user switched manually to another viewDataTable
     * Piwik will re-use the viewDataTable that was used the last time. If you want to force the usage of a
     * viewDataTable use {@link forceViewDataTable()}.
     *
     * @param string $viewDataTableId eg 'table' or 'graph'
     * @return static
     */
    public function setDefaultViewDataTable($viewDataTableId)
    {
        $this->viewDataTable = $viewDataTableId;
        return $this;
    }

    /**
     * Forces the usage of the given viewDataTable in order to render the report.
     *
     * @param string $viewDataTableId eg 'table' or 'graph'
     * @return $this
     */
    public function forceViewDataTable($viewDataTableId)
    {
        $this->forceViewDataTable = true;
        $this->setDefaultViewDataTable($viewDataTableId);

        return $this;
    }

    /**
     * Detect whether a defined viewDataTable should be forced in order to render a report.
     * @return bool
     */
    public function isViewDataTableForced()
    {
        return $this->forceViewDataTable;
    }

    /**
     * Get the specified viewDataTable.
     * @return string
     */
    public function getViewDataTable()
    {
        return $this->viewDataTable;
    }

    /**
     * @inheritdoc
     */
    public function getParameters()
    {
        $parameters = parent::getParameters();

        $defaultParams = array();

        if ($this->forceViewDataTable) {
            $defaultParams['forceView'] = '1';

            if ($this->viewDataTable) {
                // URL param is not needed for default view dataTable
                $defaultParams['viewDataTable'] = $this->viewDataTable;
            }
        }

        return $defaultParams + $parameters;
    }

    public function getReport()
    {
        return ReportsProvider::factory($this->module, $this->action);
    }

    public function isReportEnabled()
    {
        $report = $this->getReport();
        return empty($report) ? true : $report->isEnabled();
    }
}
