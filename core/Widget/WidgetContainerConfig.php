<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Widget;

use Piwik\Report\ReportWidgetConfig;

/**
 * Defines a new widget container. Widget containers are useful when you want to combine several widgets
 * into one unique widgets. For example you could combine an evolution graph widget with a sparklines widget
 * and combine them to a single "overview widget". It also allows you to specify layouts meaning you can
 * define a layout that will group multiple widgets into one widget displaying a menu on the left side for each
 * widget and the actual widget on the right side. By default widgets within a container are displayed vertically
 * one after another.
 *
 * To define a widget container just place a subclass within the `Widgets` folder of your plugin.
 *
 * @api since Piwik 3.0.0
 */
class WidgetContainerConfig extends WidgetConfig
{
    /**
     * @var WidgetConfig[]
     */
    protected $widgets = array();
    protected $layout = '';
    protected $id = '';

    protected $module = 'CoreHome';
    protected $action = 'renderWidgetContainer';
    protected $isWidgetizable = false;

    /**
     * Sets (overwrites) the id of the widget container.
     *
     * The id can be used by any plugins to add more widgets to this container and it will be also used for the unique
     * widget id and in the URL to render this widget.
     *
     * @param string $id eg 'Products' or 'Contents'
     * @return static
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get the id of the widget.
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Sets the layout of the container widget.
     *
     * By default widgets within a container are displayed one after another. In case you want to change this
     * behaviour you can specify a layout that will be recognized by the UI. It is not yet possible to define
     * custom layouts.
     *
     * @param string $layout eg 'ByDimension' see {@link Piwik\Plugins\CoreHome\CoreHome::WIDGET_CONTAINER_LAYOUT_BY_DIMENSION}
     * @return static
     */
    public function setLayout($layout)
    {
        $this->layout = $layout;
        return $this;
    }

    /**
     * Gets the currently set layout.
     * @return string
     */
    public function getLayout()
    {
        return $this->layout;
    }

    /**
     * Adds a new widget to the container widget.
     *
     * @param WidgetConfig $widget
     * @return static
     */
    public function addWidgetConfig(WidgetConfig $widget)
    {
        if ($widget instanceof ReportWidgetConfig && !$widget->isReportEnabled()) {
            return $this;
        }

        $this->widgets[] = $widget;

        return $this;
    }

    /**
     * Set (overwrite) widget configs.
     *
     * @param WidgetConfig[] $configs
     */
    public function setWidgetConfigs($configs)
    {
        $this->widgets = $configs;
    }

    /**
     * Get all added widget configs.
     *
     * @return WidgetConfig[]
     */
    public function getWidgetConfigs()
    {
        return $this->widgets;
    }

    /**
     * @inheritdoc
     */
    public function getUniqueId()
    {
        $parameters = $this->getParameters();
        unset($parameters['module']);
        unset($parameters['action']);
        unset($parameters['containerId']);

        return WidgetsList::getWidgetUniqueId($this->id, '', $parameters);
    }

    /**
     * @inheritdoc
     */
    public function getParameters()
    {
        $params = parent::getParameters();
        $params['containerId'] = $this->getId();
        return $params;
    }

}