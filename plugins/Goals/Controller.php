<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Goals;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\DataTable\Renderer\Json;
use Piwik\DataTable\Filter\AddColumnsProcessedMetricsGoal;
use Piwik\FrontController;
use Piwik\Piwik;
use Piwik\Plugin\Manager;
use Piwik\Plugins\Live\Live;
use Piwik\Plugins\Referrers\API as APIReferrers;
use Piwik\Translation\Translator;
use Piwik\View;

/**
 *
 */
class Controller extends \Piwik\Plugin\Controller
{
    /**
     * Number of "Your top converting keywords/etc are" to display in the per Goal overview page
     * @var int
     */
    const COUNT_TOP_ROWS_TO_DISPLAY = 3;

    protected $goalColumnNameToLabel = array(
        'avg_order_revenue' => 'General_AverageOrderValue',
        'nb_conversions'    => 'Goals_ColumnConversions',
        'conversion_rate'   => 'General_ColumnConversionRate',
        'revenue'           => 'General_TotalRevenue',
        'items'             => 'General_PurchasedProducts',
    );

    /**
     * @var Translator
     */
    private $translator;
    private $goals;

    private function formatConversionRate($conversionRate, $columnName = 'conversion_rate')
    {
        if ($conversionRate instanceof DataTable) {
            if ($conversionRate->getRowsCount() == 0) {
                $conversionRate = 0;
            } else {
                $conversionRate = $conversionRate->getFirstRow()->getColumn($columnName);
            }
        }

        return $conversionRate;
    }

    public function __construct(Translator $translator)
    {
        parent::__construct();

        $this->translator = $translator;

        $this->goals = Request::processRequest('Goals.getGoals', ['idSite' => $this->idSite, 'filter_limit' => '-1'], $default = []);
    }

    public function manage()
    {
        Piwik::checkUserHasWriteAccess($this->idSite);

        $view = new View('@Goals/manageGoals');
        $this->setGeneralVariablesView($view);
        $this->setEditGoalsViewVariables($view);
        $this->setGoalOptions($view);
        return $view->render();
    }

    public function goalConversionsOverview()
    {
        $view = new View('@Goals/conversionOverview');
        $idGoal = Common::getRequestVar('idGoal', null, 'string');

        $view->topDimensions = $this->getTopDimensions($idGoal);

        $goalMetrics = Request::processRequest('Goals.get', array('idGoal' => $idGoal));

        // conversion rate for new and returning visitors
        $view->conversion_rate_returning = $this->formatConversionRate($goalMetrics, 'conversion_rate_returning_visit');
        $view->conversion_rate_new = $this->formatConversionRate($goalMetrics, 'conversion_rate_new_visit');
        $view->idGoal = $idGoal;
        $view->visitorLogEnabled = Manager::getInstance()->isPluginActivated('Live') && Live::isVisitorLogEnabled($this->idSite);

        return $view->render();
    }

    public function getLastNbConversionsGraph()
    {
        $view = $this->getLastUnitGraph($this->pluginName, __FUNCTION__, 'Goals.getConversions');
        return $this->renderView($view);
    }

    public function getLastConversionRateGraph()
    {
        $view = $this->getLastUnitGraph($this->pluginName, __FUNCTION__, 'Goals.getConversionRate');
        return $this->renderView($view);
    }

    public function getLastRevenueGraph()
    {
        $view = $this->getLastUnitGraph($this->pluginName, __FUNCTION__, 'Goals.getRevenue');
        return $this->renderView($view);
    }

    public function addNewGoal()
    {
        $view = new View('@Goals/addNewGoal');
        $this->setGeneralVariablesView($view);
        $this->setGoalOptions($view);
        $view->onlyShowAddNewGoal = true;
        return $view->render();
    }

    public function editGoals()
    {
        $view = new View('@Goals/editGoals');
        $this->setGeneralVariablesView($view);
        $this->setEditGoalsViewVariables($view);
        $this->setGoalOptions($view);
        return $view->render();
    }

    public function hasConversions()
    {
        $this->checkSitePermission();

        $idGoal = Common::getRequestVar('idGoal', '', 'string');
        $period = Common::getRequestVar('period', null, 'string');
        $date   = Common::getRequestVar('date', null, 'string');

        Piwik::checkUserHasViewAccess($this->idSite);

        $conversions = new Conversions();

        Json::sendHeaderJSON();

        $numConversions = $conversions->getConversionForGoal($idGoal, $this->idSite, $period, $date);

        return json_encode($numConversions > 0);
    }

    public function getEvolutionGraph(array $columns = array(), $idGoal = false, array $defaultColumns = array())
    {
        if (empty($columns)) {
            $columns = Common::getRequestVar('columns', false);
            if (false !== $columns) {
                $columns = Piwik::getArrayFromApiParameter($columns);
            }
        }

        if (false !== $columns) {
            $columns = !is_array($columns) ? array($columns) : $columns;
        }

        if (empty($idGoal)) {
            $idGoal = Common::getRequestVar('idGoal', '', 'string');
        }
        $view = $this->getLastUnitGraph($this->pluginName, __FUNCTION__, 'Goals.get');
        $view->requestConfig->request_parameters_to_modify['idGoal'] = $idGoal;
        $view->requestConfig->request_parameters_to_modify['showAllGoalSpecificMetrics'] = 1;

        $nameToLabel = $this->goalColumnNameToLabel;
        if ($idGoal == Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_ORDER) {
            $nameToLabel['nb_conversions'] = 'General_EcommerceOrders';
        } elseif ($idGoal == Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_CART) {
            $nameToLabel['nb_conversions'] = $this->translator->translate('General_VisitsWith', $this->translator->translate('Goals_AbandonedCart'));
            $nameToLabel['conversion_rate'] = $nameToLabel['nb_conversions'];
            $nameToLabel['revenue'] = $this->translator->translate('Goals_LeftInCart', $this->translator->translate('General_ColumnRevenue'));
            $nameToLabel['items'] = $this->translator->translate('Goals_LeftInCart', $this->translator->translate('Goals_Products'));
        }

        $selectableColumns = array('nb_conversions', 'conversion_rate', 'revenue');
        $goalSelectableColumns = $selectableColumns;
        if ($this->site->isEcommerceEnabled()) {
            $selectableColumns[] = 'items';
            $selectableColumns[] = 'avg_order_revenue';
        }

        foreach (array_merge($columns ? $columns : array(), $selectableColumns) as $columnName) {
            $columnTranslation = $this->getColumnTranslation($nameToLabel, $columnName, $idGoal);
            $view->config->addTranslation($columnName, $columnTranslation);
        }

        if ($idGoal === '') {
            foreach ($this->goals as $aGoal) {
                foreach ($goalSelectableColumns as $goalColumn) {
                    $goalMetricName = Goals::makeGoalColumn($aGoal['idgoal'], $goalColumn);
                    $selectableColumns[] = $goalMetricName;
                    $columnTranslation = $this->getColumnTranslation($nameToLabel, $goalColumn, $aGoal['idgoal']);
                    $view->config->addTranslation($goalMetricName, $columnTranslation);
                }
            }
        }

        if (!empty($columns)) {
            $view->config->columns_to_display = $columns;
        } elseif (empty($view->config->columns_to_display) && !empty($defaultColumns)) {
            $view->config->columns_to_display = $defaultColumns;
        }

        $view->config->selectable_columns = $selectableColumns;

        $langString = $idGoal ? 'Goals_SingleGoalOverviewDocumentation' : 'Goals_GoalsOverviewDocumentation';
        $view->config->documentation = $this->translator->translate($langString, '<br />');

        return $this->renderView($view);
    }

    private function getColumnTranslation($nameToLabel, $columnName, $idGoal)
    {
        $columnTranslation = '';
        // find the right translation for this column, eg. find 'revenue' if column is Goal_1_revenue
        foreach ($nameToLabel as $metric => $metricTranslation) {
            if (strpos($columnName, $metric) !== false) {
                $columnTranslation = $this->translator->translate($metricTranslation);
                break;
            }
        }

        if (!empty($idGoal)
            && isset($this->goals[$idGoal])
        ) {
            $goalName = $this->goals[$idGoal]['name'];
            $columnTranslation = "$columnTranslation (" . $this->translator->translate('Goals_GoalX', "$goalName") . ")";
        }

        return $columnTranslation;
    }

    protected function getTopDimensions($idGoal)
    {
        $columnNbConversions = 'goal_' . $idGoal . '_nb_conversions';
        $columnConversionRate = 'goal_' . $idGoal . '_conversion_rate';

        $topDimensionsToLoad = array();

        if (\Piwik\Plugin\Manager::getInstance()->isPluginActivated('UserCountry')) {
            $topDimensionsToLoad += array(
                'country' => 'UserCountry.getCountry',
            );
        }

        $keywordNotDefinedString = '';
        if (\Piwik\Plugin\Manager::getInstance()->isPluginActivated('Referrers')) {
            $keywordNotDefinedString = APIReferrers::getKeywordNotDefinedString();
            $topDimensionsToLoad += array(
                'keyword' => 'Referrers.getKeywords',
                'website' => 'Referrers.getWebsites',
            );
        }
        $topDimensions = array();
        foreach ($topDimensionsToLoad as $dimensionName => $apiMethod) {
            $request = new Request("method=$apiMethod
                                   &format=original
                                   &filter_update_columns_when_show_all_goals=1
                                   &idGoal=" . AddColumnsProcessedMetricsGoal::GOALS_FULL_TABLE . "
                                   &filter_sort_order=desc
                                   &filter_sort_column=$columnNbConversions" .
                // select a couple more in case some are not valid (ie. conversions==0 or they are "Keyword not defined")
                "&filter_limit=" . (self::COUNT_TOP_ROWS_TO_DISPLAY + 2));
            $datatable = $request->process();
            $topDimension = array();
            $count = 0;
            foreach ($datatable->getRows() as $row) {
                $conversions = $row->getColumn($columnNbConversions);
                if ($conversions > 0
                    && $count < self::COUNT_TOP_ROWS_TO_DISPLAY

                    // Don't put the "Keyword not defined" in the best segment since it's irritating
                    && !($dimensionName == 'keyword'
                        && $row->getColumn('label') == $keywordNotDefinedString)
                ) {
                    $topDimension[] = array(
                        'name'            => $row->getColumn('label'),
                        'nb_conversions'  => $conversions,
                        'conversion_rate' => $this->formatConversionRate($row->getColumn($columnConversionRate)),
                        'metadata'        => $row->getMetadata(),
                    );
                    $count++;
                }
            }
            $topDimensions[$dimensionName] = $topDimension;
        }
        return $topDimensions;
    }

    protected function getMetricsForGoal($idGoal)
    {
        $request = new Request("method=Goals.get&format=original&idGoal=$idGoal");
        $datatable = $request->process();
        $dataRow = $datatable->getFirstRow();
        $nbConversions = $dataRow->getColumn('nb_conversions');
        $nbVisitsConverted = $dataRow->getColumn('nb_visits_converted');
        // Backward compatibility before 1.3, this value was not processed
        if (empty($nbVisitsConverted)) {
            $nbVisitsConverted = $nbConversions;
        }
        $revenue = $dataRow->getColumn('revenue');
        $return = array(
            'id'                         => $idGoal,
            'nb_conversions'             => (int)$nbConversions,
            'nb_visits_converted'        => (int)$nbVisitsConverted,
            'conversion_rate'            => $this->formatConversionRate($dataRow->getColumn('conversion_rate')),
            'revenue'                    => $revenue ? $revenue : 0,
            'urlSparklineConversions'    => $this->getUrlSparkline('getEvolutionGraph', array('columns' => array('nb_conversions'), 'idGoal' => $idGoal)),
            'urlSparklineConversionRate' => $this->getUrlSparkline('getEvolutionGraph', array('columns' => array('conversion_rate'), 'idGoal' => $idGoal)),
            'urlSparklineRevenue'        => $this->getUrlSparkline('getEvolutionGraph', array('columns' => array('revenue'), 'idGoal' => $idGoal)),
        );
        if ($idGoal == Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_ORDER) {
            $items = $dataRow->getColumn('items');
            $aov = $dataRow->getColumn('avg_order_revenue');
            $return = array_merge($return, array(
                                                'revenue_subtotal'              => $dataRow->getColumn('revenue_subtotal'),
                                                'revenue_tax'                   => $dataRow->getColumn('revenue_tax'),
                                                'revenue_shipping'              => $dataRow->getColumn('revenue_shipping'),
                                                'revenue_discount'              => $dataRow->getColumn('revenue_discount'),

                                                'items'                         => $items ? $items : 0,
                                                'avg_order_revenue'             => $aov ? $aov : 0,
                                                'urlSparklinePurchasedProducts' => $this->getUrlSparkline('getEvolutionGraph', array('columns' => array('items'), 'idGoal' => $idGoal)),
                                                'urlSparklineAverageOrderValue' => $this->getUrlSparkline('getEvolutionGraph', array('columns' => array('avg_order_revenue'), 'idGoal' => $idGoal)),
            ));
        }
        return $return;
    }

    private function setEditGoalsViewVariables($view)
    {
        $goals = $this->goals;

        // unsanitize goal names and other text data (not done in API so as not to break
        // any other code/cause security issues)
        foreach ($goals as &$goal) {
            $goal['name'] = Common::unsanitizeInputValue($goal['name']);
            $goal['description'] = Common::unsanitizeInputValue($goal['description']);
            if (isset($goal['pattern'])) {
                $goal['pattern'] = Common::unsanitizeInputValue($goal['pattern']);
            }
        }

        $view->goals = $goals;

        $idGoal = Common::getRequestVar('idGoal', 0, 'int');
        $view->idGoal = 0;
        if ($idGoal && array_key_exists($idGoal, $goals)) {
            $view->idGoal = $idGoal;
        }

        $view->goalsJSON = json_encode($goals);
        $view->ecommerceEnabled = $this->site->isEcommerceEnabled();
    }

    private function setGoalOptions(View $view)
    {
        $view->translationHelper = new TranslationHelper();
        $view->userCanEditGoals = Piwik::isUserHasWriteAccess($this->idSite);
        $view->goalTriggerTypeOptions = array(
            'visitors' => Piwik::translate('Goals_WhenVisitors'),
            'manually' => Piwik::translate('Goals_Manually')
        );
        $view->goalMatchAttributeOptions = array(
            array('key' => 'url', 'value' => Piwik::translate('Goals_VisitUrl')),
            array('key' => 'title', 'value' => Piwik::translate('Goals_VisitPageTitle')),
            array('key' => 'event', 'value' => Piwik::translate('Goals_SendEvent')),
            array('key' => 'file', 'value' => Piwik::translate('Goals_Download')),
            array('key' => 'external_website', 'value' => Piwik::translate('Goals_ClickOutlink')),
            ['key' => 'visit_duration', 'value' => Piwik::translate('Goals_VisitDurationMatchAttr')],
            ['key' => 'visit_nb_pageviews', 'value' => Piwik::translate('Goals_VisitedPagesMatchAttr')],
        );
        $view->allowMultipleOptions = array(
            array('key' => '0', 'value' => Piwik::translate('Goals_DefaultGoalConvertedOncePerVisit')),
            array('key' => '1', 'value' => Piwik::translate('Goals_AllowGoalConvertedMoreThanOncePerVisit'))
        );
        $view->eventTypeOptions = array(
            array('key' => 'event_category', 'value' => Piwik::translate('Events_EventCategory')),
            array('key' => 'event_action', 'value' => Piwik::translate('Events_EventAction')),
            array('key' => 'event_name', 'value' => Piwik::translate('Events_EventName'))
        );
        $view->patternTypeOptions = array(
            array('key' => 'contains', 'value' => Piwik::translate('Goals_Contains', '')),
            array('key' => 'exact', 'value' => Piwik::translate('Goals_IsExactly', '')),
            array('key' => 'regex', 'value' => Piwik::translate('Goals_MatchesExpression', ''))
        );
        $view->numericComparisonTypeOptions = [
            ['key' => 'greater_than', 'value' => Piwik::translate('General_OperationGreaterThan')],
        ];
    }

    /**
     * @deprecated used to be a widgetized URL. There to not break widget URLs
     */
    public function widgetGoalReport()
    {
        $idGoal = Common::getRequestVar('idGoal', '', 'string');

        if ($idGoal === Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_ORDER) {
            $_GET['containerId'] = 'EcommerceOverview';
        } elseif (!empty($idGoal)) {
            $_GET['containerId'] = 'Goal_' . (int) $idGoal;
        } else {
            return '';
        }

        return FrontController::getInstance()->fetchDispatch('CoreHome', 'renderWidgetContainer');
    }

    /**
     * @deprecated used to be a widgetized URL. There to not break widget URLs
     */
    public function widgetGoalsOverview()
    {
        $_GET['containerId'] = 'GoalsOverview';

        return FrontController::getInstance()->fetchDispatch('CoreHome', 'renderWidgetContainer');
    }
}
