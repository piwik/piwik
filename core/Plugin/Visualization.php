<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugin;

use Piwik\API\DataTablePostProcessor;
use Piwik\API\Proxy;
use Piwik\API\Request;
use Piwik\API\Request as ApiRequest;
use Piwik\API\ResponseBuilder;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Http\BadRequestException;
use Piwik\Log;
use Piwik\Metrics\Formatter\Html as HtmlFormatter;
use Piwik\NoAccessException;
use Piwik\Notification;
use Piwik\Option;
use Piwik\Period;
use Piwik\Piwik;
use Piwik\Plugins\API\API as ApiApi;
use Piwik\Plugins\API\Filter\DataComparisonFilter;
use Piwik\Plugins\Monolog\Processor\ExceptionToTextProcessor;
use Piwik\Plugins\PrivacyManager\PrivacyManager;
use Piwik\SettingsPiwik;
use Piwik\View;
use Piwik\ViewDataTable\Manager as ViewDataTableManager;
use Piwik\Plugin\Manager as PluginManager;
use Psr\Log\LoggerInterface;

/**
 * The base class for report visualizations that output HTML and use JavaScript.
 *
 * Report visualizations that extend from this class will be displayed like all others in
 * the Piwik UI. The following extra UI controls will be displayed around the visualization
 * itself:
 *
 * - report documentation,
 * - a header message (if {@link Piwik\ViewDataTable\Config::$show_header_message} is set),
 * - a footer message (if {@link Piwik\ViewDataTable\Config::$show_footer_message} is set),
 * - a list of links to related reports (if {@link Piwik\ViewDataTable\Config::$related_reports} is set),
 * - a button that allows users to switch visualizations,
 * - a control that allows users to export report data in different formats,
 * - a limit control that allows users to change the amount of rows displayed (if
 *   {@link Piwik\ViewDataTable\Config::$show_limit_control} is true),
 * - and more depending on the visualization.
 *
 * ### Rendering Process
 *
 * The following process is used to render reports:
 *
 * - The report is loaded through Piwik's Reporting API.
 * - The display and request properties that require report data in order to determine a default
 *   value are defaulted. These properties are:
 *
 *   - {@link Piwik\ViewDataTable\Config::$columns_to_display}
 *   - {@link Piwik\ViewDataTable\RequestConfig::$filter_sort_column}
 *   - {@link Piwik\ViewDataTable\RequestConfig::$filter_sort_order}
 *
 * - Priority filters are applied to the report (see {@link Piwik\ViewDataTable\Config::$filters}).
 * - The filters that are applied to every report in the Reporting API (called **generic filters**)
 *   are applied. (see {@link Piwik\API\Request})
 * - The report's queued filters are applied.
 * - A {@link Piwik\View} instance is created and rendered.
 *
 * ### Rendering Hooks
 *
 * The Visualization class defines several overridable methods that are called at specific
 * points during the rendering process. Derived classes can override these methods change
 * the data that is displayed or set custom properties.
 *
 * The overridable methods (called **rendering hooks**) are as follows:
 *
 * - **beforeLoadDataTable**: Called at the start of the rendering process before any data
 *                            is loaded.
 * - **beforeGenericFiltersAreAppliedToLoadedDataTable**: Called after data is loaded and after priority
 *                                                        filters are called, but before other filters. This
 *                                                        method should be used if you need the report's
 *                                                        entire dataset.
 * - **afterGenericFiltersAreAppliedToLoadedDataTable**: Called after generic filters are applied, but before
 *                                                       queued filters are applied.
 * - **afterAllFiltersAreApplied**: Called after data is loaded and all filters are applied.
 * - **beforeRender**: Called immediately before a {@link Piwik\View} is created and rendered.
 * - **isThereDataToDisplay**: Called after a {@link Piwik\View} is created to determine if the report has
 *                             data or not. If not, a message is displayed to the user.
 *
 * ### The DataTable JavaScript class
 *
 * In the UI, visualization behavior is provided by logic in the **DataTable** JavaScript class.
 * When creating new visualizations, the **DataTable** JavaScript class (or one of its existing
 * descendants) should be extended.
 *
 * To learn more read the [Visualizing Report Data](/guides/visualizing-report-data#creating-new-visualizations)
 * guide.
 *
 * ### Examples
 *
 * **Changing the data that is loaded**
 *
 *     class MyVisualization extends Visualization
 *     {
 *         // load the previous period's data as well as the requested data. this will change
 *         // $this->dataTable from a DataTable instance to a DataTable\Map instance.
 *         public function beforeLoadDataTable()
 *         {
 *             $date = Common::getRequestVar('date');
 *             list($previousDate, $ignore) = Range::getLastDate($date, $period);
 *
 *             $this->requestConfig->request_parameters_to_modify['date'] = $previousDate . ',' . $date;
 *         }
 *
 *         // since we load the previous period's data too, we need to override the logic to
 *         // check if there is data or not.
 *         public function isThereDataToDisplay()
 *         {
 *             $tables = $this->dataTable->getDataTables()
 *             $requestedDataTable = end($tables);
 *
 *             return $requestedDataTable->getRowsCount() != 0;
 *         }
 *     }
 *
 * **Force properties to be set to certain values**
 *
 *     class MyVisualization extends Visualization
 *     {
 *         // ensure that some properties are set to certain values before rendering.
 *         // this will overwrite any changes made by plugins that use this visualization.
 *         public function beforeRender()
 *         {
 *             $this->config->max_graph_elements = false;
 *             $this->config->datatable_js_type  = 'MyVisualization';
 *             $this->config->show_flatten_table = false;
 *             $this->config->show_pagination_control = false;
 *             $this->config->show_offset_information = false;
 *         }
 *     }
 */
class Visualization extends ViewDataTable
{
    /**
     * The Twig template file to use when rendering, eg, `"@MyPlugin/_myVisualization.twig"`.
     *
     * Must be defined by classes that extend Visualization.
     *
     * @api
     */
    const TEMPLATE_FILE = '';
    const NO_PROFILABLE_DATA_NOTIFICATION_ID = 'no_profilable_data_reports_disabled';

    private $templateVars = array();
    private $reportLastUpdatedMessage = null;
    private $metadata = null;
    protected $metricsFormatter = null;

    /**
     * @var Report
     */
    protected $report;

    final public function __construct($controllerAction, $apiMethodToRequestDataTable, $params = array())
    {
        $templateFile = static::TEMPLATE_FILE;

        if (empty($templateFile)) {
            throw new \Exception('You have not defined a constant named TEMPLATE_FILE in your visualization class.');
        }

        $this->metricsFormatter = new HtmlFormatter();

        parent::__construct($controllerAction, $apiMethodToRequestDataTable, $params);

        $this->report = ReportsProvider::factory($this->requestConfig->getApiModuleToRequest(), $this->requestConfig->getApiMethodToRequest());
    }

    public function render()
    {
        $this->overrideSomeConfigPropertiesIfNeeded();

        try {
            $this->beforeLoadDataTable();
            $this->loadDataTableFromAPI();
            $this->postDataTableLoadedFromAPI();

            $requestPropertiesAfterLoadDataTable = $this->requestConfig->getProperties();

            $this->applyFilters();
            $this->addVisualizationInfoFromMetricMetadata();
            $this->afterAllFiltersAreApplied();

            $this->beforeRender();
            $this->fireBeforeRenderHook();

            $this->logMessageIfRequestPropertiesHaveChanged($requestPropertiesAfterLoadDataTable);
        } catch (NoAccessException $e) {
            throw $e;
        } catch (\Exception $e) {
            StaticContainer::get(LoggerInterface::class)->error('Failed to get data from API: {exception}', [
                'exception' => $e,
                'ignoreInScreenWriter' => true,
            ]);

            $message = ExceptionToTextProcessor::getMessageAndWholeBacktrace($e);

            $loadingError = array('message' => $message);
        }

        $view = new View("@CoreHome/_dataTable");
        $view->assign($this->templateVars);

        $this->showNotificationIfDataIsNotProfilable($view);

        if (!empty($loadingError)) {
            $view->error = $loadingError;
        }

        $view->visualization         = $this;
        $view->visualizationTemplate = static::TEMPLATE_FILE;
        $view->visualizationCssClass = $this->getDefaultDataTableCssClass();
        $view->reportMetdadata = $this->getReportMetadata();

        if (null === $this->dataTable) {
            $view->dataTable = null;
        } else {
            $view->dataTableHasNoData = !$this->isThereDataToDisplay();
            $view->dataTable          = $this->dataTable;

            // if it's likely that the report data for this data table has been purged,
            // set whether we should display a message to that effect.
            $view->showReportDataWasPurgedMessage = $this->hasReportBeenPurged();
            $view->deleteReportsOlderThan         = Option::get('delete_reports_older_than');
        }

        $view->idSubtable  = $this->requestConfig->idSubtable;
        $clientSideParameters = $this->getClientSideParametersToSet();
        if (isset($clientSideParameters['showtitle'])) {
            unset($clientSideParameters['showtitle']);
        }
        $view->clientSideParameters = $clientSideParameters;
        $view->clientSideProperties = $this->getClientSidePropertiesToSet();
        $view->properties  = array_merge($this->requestConfig->getProperties(), $this->config->getProperties());
        $view->reportLastUpdatedMessage = $this->reportLastUpdatedMessage;
        $view->footerIcons = $this->config->footer_icons;
        $view->isWidget    = Common::getRequestVar('widget', 0, 'int');
        $view->notifications = [];
        $view->isComparing = $this->isComparing();

        if (!$this->supportsComparison()
            && DataComparisonFilter::isCompareParamsPresent()
            && empty($view->dataTableHasNoData)
        ) {
            if (empty($view->properties['show_footer_message'])) {
                $view->properties['show_footer_message'] = '';
            }
            $view->properties['show_footer_message'] .= '<br/>' . Piwik::translate('General_VisualizationDoesNotSupportComparison');
        }

        $this->appendFooterMessageIfRequiresProfilableAndNotProfilablePeriod($view);

        if (empty($this->dataTable) || !$this->hasAnyData($this->dataTable)) {
            /**
             * @ignore
             */
            Piwik::postEvent('Visualization.onNoData', [$view]);
        }

        return $view->render();
    }

    protected function checkRequestIsNotForMultiplePeriods()
    {
        $date = $this->requestConfig->getRequestParam('date');
        $period = $this->requestConfig->getRequestParam('period');
        if (Period::isMultiplePeriod($date, $period)) {
            throw new BadRequestException("The '" . static::ID . "' visualization does not support multiple periods.");
        }
    }

    protected function checkRequestIsOnlyForMultiplePeriods()
    {
        try {
            $this->checkRequestIsNotForMultiplePeriods();
        } catch (BadRequestException $ex) {
            return; // ignore
        }

        throw new BadRequestException("The '" . static::ID . "' visualization does not support single periods.");
    }

    private function hasAnyData(DataTable\DataTableInterface $dataTable)
    {
        $hasData = false;
        $dataTable->filter(function (DataTable $table) use (&$hasData) {
            if ($hasData || $table->getRowsCount() == 0) {
                return;
            }

            foreach ($table->getRows() as $row) {
                foreach ($row->getColumns() as $column => $value) {
                    if ($value != 0 && $value !== '0%') {
                        $hasData = true;
                        return;
                    }
                }
            }
        });
        return $hasData;
    }

    /**
     * @internal
     */
    protected function loadDataTableFromAPI()
    {
        if (!is_null($this->dataTable)) {
            // data table is already there
            // this happens when setDataTable has been used
            return $this->dataTable;
        }

        // we build the request (URL) to call the API
        $request = $this->buildApiRequestArray();

        $module = $this->requestConfig->getApiModuleToRequest();
        $method = $this->requestConfig->getApiMethodToRequest();

        list($module, $method) = Request::getRenamedModuleAndAction($module, $method);

        PluginManager::getInstance()->checkIsPluginActivated($module);

        $proxyRequestParams = $request;
        if ($this->isComparing()) {
            $proxyRequestParams = array_merge($proxyRequestParams, [
                'disable_root_datatable_post_processor' => 1,
            ]);
        }

        $class     = ApiRequest::getClassNameAPI($module);
        $dataTable = Proxy::getInstance()->call($class, $method, $proxyRequestParams);

        $response = new ResponseBuilder($format = 'original', $request);
        $response->disableSendHeader();
        $response->disableDataTablePostProcessor();

        $this->dataTable = $response->getResponse($dataTable, $module, $method);
    }

    private function getReportMetadata()
    {
        $request = $this->request->getRequestArray() + $_GET + $_POST;

        $idSite  = Common::getRequestVar('idSite', null, 'int', $request);
        $module  = $this->requestConfig->getApiModuleToRequest();
        $action  = $this->requestConfig->getApiMethodToRequest();

        $apiParameters = array();
        $entityNames = StaticContainer::get('entities.idNames');
        foreach ($entityNames as $entityName) {
            $idEntity = Common::getRequestVar($entityName, 0, 'int');
            if ($idEntity > 0) {
                $apiParameters[$entityName] = $idEntity;
            }
        }

        $metadata = ApiApi::getInstance()->getMetadata($idSite, $module, $action, $apiParameters);

        if (!empty($metadata)) {
            return array_shift($metadata);
        }

        return false;
    }

    private function overrideSomeConfigPropertiesIfNeeded()
    {
        if (empty($this->config->footer_icons)) {
            $this->config->footer_icons = ViewDataTableManager::configureFooterIcons($this);
        }

        if (!$this->isPluginActivated('Goals')) {
            $this->config->show_goals = false;
        }
    }

    private function isPluginActivated($pluginName)
    {
        return PluginManager::getInstance()->isPluginActivated($pluginName);
    }

    /**
     * Assigns a template variable making it available in the Twig template specified by
     * {@link TEMPLATE_FILE}.
     *
     * @param array|string $vars One or more variable names to set.
     * @param mixed $value The value to set each variable to.
     * @api
     */
    public function assignTemplateVar($vars, $value = null)
    {
        if (is_string($vars)) {
            $this->templateVars[$vars] = $value;
        } elseif (is_array($vars)) {
            foreach ($vars as $key => $value) {
                $this->templateVars[$key] = $value;
            }
        }
    }

    /**
     * Returns `true` if there is data to display, `false` if otherwise.
     *
     * Derived classes should override this method if they change the amount of data that is loaded.
     *
     * @api
     */
    protected function isThereDataToDisplay()
    {
        return !empty($this->dataTable) && 0 < $this->dataTable->getRowsCount();
    }

    /**
     * Hook called after the dataTable has been loaded from the API
     * Can be used to add, delete or modify the data freshly loaded
     *
     * @return bool
     */
    private function postDataTableLoadedFromAPI()
    {
        $columns = $this->dataTable->getColumns();
        $hasNbVisits       = in_array('nb_visits', $columns);
        $hasNbUniqVisitors = in_array('nb_uniq_visitors', $columns);

        // if any comparison period doesn't support unique visitors, we can't display it for the main table
        if ($this->isComparing()) {
            $request = $this->getRequestArray();
            if (!empty($request['comparePeriods'])) {
                foreach ($request['comparePeriods'] as $comparePeriod) {
                    if (!SettingsPiwik::isUniqueVisitorsEnabled($comparePeriod)) {
                        $hasNbUniqVisitors = false;
                        break;
                    }
                }
            }
        }

        // default columns_to_display to label, nb_uniq_visitors/nb_visits if those columns exist in the
        // dataset. otherwise, default to all columns in dataset.
        if (empty($this->config->columns_to_display)) {
            $this->config->setDefaultColumnsToDisplay($columns, $hasNbVisits, $hasNbUniqVisitors);
        }

        if (empty($this->requestConfig->filter_sort_column)) {
            $this->requestConfig->setDefaultSort($this->config->columns_to_display, $hasNbUniqVisitors, $columns);
        }

        // deal w/ table metadata
        if ($this->dataTable instanceof DataTable) {
            $this->metadata = $this->dataTable->getAllTableMetadata();

            if (isset($this->metadata[DataTable::ARCHIVED_DATE_METADATA_NAME])) {
                $this->reportLastUpdatedMessage = $this->makePrettyArchivedOnText();
            }
        }

        $pivotBy = Common::getRequestVar('pivotBy', false) ?: $this->requestConfig->pivotBy;
        if (empty($pivotBy)
            && $this->dataTable instanceof DataTable
        ) {
            $this->config->disablePivotBySubtableIfTableHasNoSubtables($this->dataTable);
        }
    }

    private function addVisualizationInfoFromMetricMetadata()
    {
        $dataTable = $this->dataTable instanceof DataTable\Map ? $this->dataTable->getFirstRow() : $this->dataTable;

        $metrics = Report::getMetricsForTable($dataTable, $this->report);

        // TODO: instead of iterating & calling translate everywhere, maybe we can get all translated names in one place.
        //       may be difficult, though, since translated metrics are specific to the report.
        foreach ($metrics as $metric) {
            $name = $metric->getName();

            if (empty($this->config->translations[$name])) {
                $this->config->translations[$name] = $metric->getTranslatedName();
            }

            if (empty($this->config->metrics_documentation[$name])) {
                $this->config->metrics_documentation[$name] = $metric->getDocumentation();
            }
        }
    }

    private function applyFilters()
    {
        $postProcessor = $this->makeDataTablePostProcessor(); // must be created after requestConfig is final
        $self = $this;

        $postProcessor->setCallbackBeforeGenericFilters(function (DataTable\DataTableInterface $dataTable) use ($self, $postProcessor) {

            $self->setDataTable($dataTable);

            // First, filters that delete rows
            foreach ($self->config->getPriorityFilters() as $filter) {
                $dataTable->filter($filter[0], $filter[1]);
            }

            $self->beforeGenericFiltersAreAppliedToLoadedDataTable();

            if (!in_array($self->requestConfig->filter_sort_column, $self->config->columns_to_display)) {
                $hasNbUniqVisitors = in_array('nb_uniq_visitors', $self->config->columns_to_display);
                $columns = $dataTable->getColumns();
                $self->requestConfig->setDefaultSort($self->config->columns_to_display, $hasNbUniqVisitors, $columns);
            }

            $postProcessor->setRequest($self->buildApiRequestArray());
        });

        $postProcessor->setCallbackAfterGenericFilters(function (DataTable\DataTableInterface $dataTable) use ($self) {

            $self->setDataTable($dataTable);
            $self->afterGenericFiltersAreAppliedToLoadedDataTable();

            // queue other filters so they can be applied later if queued filters are disabled
            foreach ($self->config->getPresentationFilters() as $filter) {
                $dataTable->queueFilter($filter[0], $filter[1]);
            }

            if (!empty($this->dataTable)) {
                $self->removeEmptyColumnsFromDisplay();
            }
        });

        $this->dataTable = $postProcessor->process($this->dataTable);
    }

    private function removeEmptyColumnsFromDisplay()
    {
        if ($this->dataTable instanceof DataTable\Map) {
            $emptyColumns = $this->dataTable->getMetadataIntersectArray(DataTable::EMPTY_COLUMNS_METADATA_NAME);
        } else {
            $emptyColumns = $this->dataTable->getMetadata(DataTable::EMPTY_COLUMNS_METADATA_NAME);
        }

        if (is_array($emptyColumns)) {
            foreach ($emptyColumns as $emptyColumn) {
                $key = array_search($emptyColumn, $this->config->columns_to_display);
                if ($key !== false) {
                    unset($this->config->columns_to_display[$key]);
                }
            }

            $this->config->columns_to_display = array_values($this->config->columns_to_display);
        }
    }

    /**
     * Returns prettified and translated text that describes when a report was last updated.
     *
     * @return string
     */
    private function makePrettyArchivedOnText()
    {
        $dateText = $this->metadata[DataTable::ARCHIVED_DATE_METADATA_NAME];
        $date     = Date::factory($dateText);
        $today    = mktime(0, 0, 0);

        if ($date->getTimestamp() > $today) {
            $elapsedSeconds = time() - $date->getTimestamp();
            $timeAgo        = $this->metricsFormatter->getPrettyTimeFromSeconds($elapsedSeconds);

            return Piwik::translate('CoreHome_ReportGeneratedXAgo', $timeAgo);
        }

        $prettyDate = $date->getLocalized(Date::DATE_FORMAT_SHORT);

        $timezoneAppend = ' (UTC)';
        return Piwik::translate('CoreHome_ReportGeneratedOn', $prettyDate) . $timezoneAppend;
    }

    /**
     * Returns true if it is likely that the data for this report has been purged and if the
     * user should be told about that.
     *
     * In order for this function to return true, the following must also be true:
     * - The data table for this report must either be empty or not have been fetched.
     * - The period of this report is not a multiple period.
     * - The date of this report must be older than the delete_reports_older_than config option.
     * @return bool
     */
    private function hasReportBeenPurged()
    {
        if (!$this->isPluginActivated('PrivacyManager')) {
            return false;
        }

        return PrivacyManager::hasReportBeenPurged($this->dataTable);
    }

    /**
     * Returns array of properties that should be visible to client side JavaScript. The data
     * will be available in the data-props HTML attribute of the .dataTable div.
     *
     * @return array Maps property names w/ property values.
     */
    private function getClientSidePropertiesToSet()
    {
        $result = array();

        foreach ($this->config->clientSideProperties as $name) {
            if (property_exists($this->requestConfig, $name)) {
                $result[$name] = $this->getIntIfValueIsBool($this->requestConfig->$name);
            } elseif (property_exists($this->config, $name)) {
                $result[$name] = $this->getIntIfValueIsBool($this->config->$name);
            }
        }

        return $result;
    }

    private function getIntIfValueIsBool($value)
    {
        return is_bool($value) ? (int)$value : $value;
    }

    /**
     * This functions reads the customization values for the DataTable and returns an array (name,value) to be printed in Javascript.
     * This array defines things such as:
     * - name of the module & action to call to request data for this table
     * - optional filters information, eg. filter_limit and filter_offset
     * - etc.
     *
     * The values are loaded:
     * - from the generic filters that are applied by default @see Piwik\API\DataTableGenericFilter::getGenericFiltersInformation()
     * - from the values already available in the GET array
     * - from the values set using methods from this class (eg. setSearchPattern(), setLimit(), etc.)
     *
     * @return array eg. array('show_offset_information' => 0, 'show_...
     */
    protected function getClientSideParametersToSet()
    {
        // build javascript variables to set
        $javascriptVariablesToSet = array();

        foreach ($this->config->custom_parameters as $name => $value) {
            $javascriptVariablesToSet[$name] = $value;
        }

        foreach ($_GET as $name => $value) {
            try {
                $requestValue = Common::getRequestVar($name);
            } catch (\Exception $e) {
                $requestValue = '';
            }
            $javascriptVariablesToSet[$name] = $requestValue;
        }

        foreach ($this->requestConfig->clientSideParameters as $name) {
            if (isset($javascriptVariablesToSet[$name])) {
                continue;
            }

            $valueToConvert = false;

            if (property_exists($this->requestConfig, $name)) {
                $valueToConvert = $this->requestConfig->$name;
            } elseif (property_exists($this->config, $name)) {
                $valueToConvert = $this->config->$name;
            }

            if (false !== $valueToConvert) {
                $javascriptVariablesToSet[$name] = $this->getIntIfValueIsBool($valueToConvert);
            }
        }

        $javascriptVariablesToSet['module'] = $this->config->controllerName;
        $javascriptVariablesToSet['action'] = $this->config->controllerAction;
        if (!isset($javascriptVariablesToSet['viewDataTable'])) {
            $javascriptVariablesToSet['viewDataTable'] = static::getViewDataTableId();
        }

        if ($this->dataTable &&
            // Set doesn't have the method
            !($this->dataTable instanceof DataTable\Map)
            && empty($javascriptVariablesToSet['totalRows'])
        ) {
            $javascriptVariablesToSet['totalRows'] =
                $this->dataTable->getMetadata(DataTable::TOTAL_ROWS_BEFORE_LIMIT_METADATA_NAME) ?: $this->dataTable->getRowsCount();
        }

        $deleteFromJavascriptVariables = array(
            'filter_excludelowpop',
            'filter_excludelowpop_value',
        );

        foreach ($deleteFromJavascriptVariables as $name) {
            if (isset($javascriptVariablesToSet[$name])) {
                unset($javascriptVariablesToSet[$name]);
            }
        }

        $rawSegment = \Piwik\API\Request::getRawSegmentFromRequest();
        if (!empty($rawSegment)) {
            $javascriptVariablesToSet['segment'] = $rawSegment;
        }

        if (isset($javascriptVariablesToSet['compareSegments'])) {
            $javascriptVariablesToSet['compareSegments'] = Common::unsanitizeInputValues($javascriptVariablesToSet['compareSegments']);
        }

        return $javascriptVariablesToSet;
    }

    /**
     * Hook that is called before loading report data from the API.
     *
     * Use this method to change the request parameters that is sent to the API when requesting
     * data.
     *
     * @api
     */
    public function beforeLoadDataTable()
    {
    }

    /**
     * Hook that is executed before generic filters are applied.
     *
     * Use this method if you need access to the entire dataset (since generic filters will
     * limit and truncate reports).
     *
     * @api
     */
    public function beforeGenericFiltersAreAppliedToLoadedDataTable()
    {
    }

    /**
     * Hook that is executed after generic filters are applied.
     *
     * @api
     */
    public function afterGenericFiltersAreAppliedToLoadedDataTable()
    {
    }

    /**
     * Hook that is executed after the report data is loaded and after all filters have been applied.
     * Use this method to format the report data before the view is rendered.
     *
     * @api
     */
    public function afterAllFiltersAreApplied()
    {
    }

    /**
     * Hook that is executed directly before rendering. Use this hook to force display properties to
     * be a certain value, despite changes from plugins and query parameters.
     *
     * @api
     */
    public function beforeRender()
    {
        // eg $this->config->showFooterColumns = true;
    }

    private function fireBeforeRenderHook()
    {
        /**
         * Posted immediately before rendering the view. Plugins can use this event to perform last minute
         * configuration of the view based on it's data or the report being viewed.
         *
         * @param Visualization $view The instance to configure.
         */
        Piwik::postEvent('Visualization.beforeRender', [$this]);
    }

    private function makeDataTablePostProcessor()
    {
        $request = $this->buildApiRequestArray();
        $module  = $this->requestConfig->getApiModuleToRequest();
        $method  = $this->requestConfig->getApiMethodToRequest();

        $processor = new DataTablePostProcessor($module, $method, $request);
        $processor->setFormatter($this->metricsFormatter);

        return $processor;
    }

    private function logMessageIfRequestPropertiesHaveChanged(array $requestPropertiesBefore)
    {
        $requestProperties = $this->requestConfig->getProperties();

        $diff = array_diff_assoc($this->makeSureArrayContainsOnlyStrings($requestProperties),
                                 $this->makeSureArrayContainsOnlyStrings($requestPropertiesBefore));

        if (!empty($diff['filter_sort_column'])) {
            // this here might be ok as it can be changed after data loaded but before filters applied
            unset($diff['filter_sort_column']);
        }
        if (!empty($diff['filter_sort_order'])) {
            // this here might be ok as it can be changed after data loaded but before filters applied
            unset($diff['filter_sort_order']);
        }

        if (empty($diff)) {
            return;
        }

        $details = array(
            'changedProperties' => $diff,
            'apiMethod'         => $this->requestConfig->apiMethodToRequestDataTable,
            'controller'        => $this->config->controllerName . '.' . $this->config->controllerAction,
            'viewDataTable'     => static::getViewDataTableId()
        );

        $message = 'Some ViewDataTable::requestConfig properties have changed after requesting the data table. '
                 . 'That means the changed values had probably no effect. For instance in beforeRender() hook. '
                 . 'Probably a bug? Details:'
                 . print_r($details, 1);

        Log::warning($message);
    }

    private function makeSureArrayContainsOnlyStrings($array)
    {
        $result = array();

        foreach ($array as $key => $value) {
            $result[$key] = json_encode($value);
        }

        return $result;
    }

    /**
     * @internal
     *
     * @return array
     */
    public function buildApiRequestArray()
    {
        $request = $this->getRequestArray();

        if (false === $this->config->enable_sort) {
            $request['filter_sort_column'] = '';
            $request['filter_sort_order'] = '';
        }

        if (!array_key_exists('format_metrics', $request) || $request['format_metrics'] === 'bc') {
            $request['format_metrics'] = '1';
        }

        if (!$this->requestConfig->disable_queued_filters && array_key_exists('disable_queued_filters', $request)) {
            unset($request['disable_queued_filters']);
        }

        if ($this->isComparing()) {
            $request['compare'] = '1';
        }

        return $request;
    }

    private function showNotificationIfDataIsNotProfilable(View $dataTableView)
    {
        if (!$this->report->isRequiresProfilableData()) {
            return;
        }

        $isProfilable = ApiRequest::isCurrentPeriodProfilable();
        if ($isProfilable) {
            return;
        }

        $view = new View("@CoreHome/_nonProfilableDataWarning.twig");

        $notification = new Notification($view->render());
        $notification->priority = Notification::PRIORITY_HIGH;
        $notification->context = Notification::CONTEXT_INFO;
        $notification->flags = Notification::FLAG_CLEAR;
        $notification->type = Notification::TYPE_TRANSIENT;
        $notification->raw = true;

        $dataTableView->notifications[self::NO_PROFILABLE_DATA_NOTIFICATION_ID] = $notification;
    }

    private function appendFooterMessageIfRequiresProfilableAndNotProfilablePeriod(View $view)
    {
        if (!$this->report || !$this->report->isRequiresProfilableData()) {
            return;
        }

        if (Request::isCurrentPeriodProfilable()) {
            return;
        }

        $date = $this->requestConfig->getRequestParam('date');
        $period = $this->requestConfig->getRequestParam('period');
        $periodObj = Period\Factory::build($period, $date);
        $periodStr = $periodObj->getPrettyString();

        if (empty($view->properties['show_footer_message'])) {
            $view->properties['show_footer_message'] = '';
        }
        $view->properties['show_footer_message'] .= '<br/>' . Piwik::translate('General_NoProfilableDataFooterMessage', [$periodStr]);
    }
}
