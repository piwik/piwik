<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Visualization;

use Piwik\Common;
use Piwik\Piwik;
use Piwik\View\ViewInterface;

/**
 * Renders a sparkline image given a PHP data array.
 * Using the Sparkline PHP Graphing Library sparkline.org
 */
class Sparkline implements ViewInterface
{
    const DEFAULT_WIDTH = 200;
    const DEFAULT_HEIGHT = 50;
    const MAX_WIDTH = 1000;
    const MAX_HEIGHT = 1000;


    /**
     * Width of the sparkline
     * @var int
     */
    protected $_width = self::DEFAULT_WIDTH;
    /**
     * Height of sparkline
     * @var int
     */
    protected $_height = self::DEFAULT_HEIGHT;
    private $serieses = array();
    /**
     * @var \Davaxi\Sparkline
     */
    private $sparkline;

    /**
     * Array with format: array( x, y, z, ... )
     * @param array $data,...
     */
    public function setValues()
    {
        $this->serieses = func_get_args();
    }

    public function addSeries(array $values)
    {
        $this->serieses[] = $values;
    }

    public function main()
    {
        try {
            $sparkline = new \Davaxi\Sparkline();
        } catch (\Exception $exception) {
            // Ignore GD not installed exception
            return;
        }

        $thousandSeparator = Piwik::translate('Intl_NumberSymbolGroup');
        $decimalSeparator = Piwik::translate('Intl_NumberSymbolDecimal');

        $sparkline->setData(); // remove default series
        foreach ($this->serieses as $seriesIndex => $series) {
            $values = [];
            $hasFloat = false;

            foreach ($series as $value) {
                // replace localized decimal separator
                $value = str_replace($thousandSeparator, '', $value);
                $value = str_replace($decimalSeparator, '.', $value);

                // sanitize value
                $value = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_SCIENTIFIC);

                if (empty($value) || !is_numeric($value)) {
                    $value = 0;
                }

                $values[] = $value;

                if (is_float($value + 0)) { // coerce to int/float type before checking
                    $hasFloat = true;
                }
            }

            // the sparkline lib used converts everything to integers (see the FormatTrait.php file) which means float
            // numbers that are close to 1.0 or 0.0 will get floored. this can happen in the average page generation time
            // report, and cause some values which are, eg, around ~.9 to appear as 0 in the sparkline. to workaround this, we
            // scale the values.
            if ($hasFloat) {
                $values = array_map(function ($x) {
                    return $x * 1000.0;
                }, $values);
            }

            $sparkline->addSeries($values);
            $this->setSparklineColors($sparkline, $seriesIndex);
        }

        $sparkline->setWidth($this->getWidth());
        $sparkline->setHeight($this->getHeight());
        $sparkline->setLineThickness(1);
        $sparkline->setPadding('5');

        $this->sparkline = $sparkline;
    }

    /**
     * Returns the width of the sparkline
     * @return int
     */
    public function getWidth() {
        return $this->_width;
    }

    /**
     * Sets the width of the sparkline
     * @param int $width
     */
    public function setWidth($width) {
        if (!is_numeric($width) || $width <= 0) {
            return;
        }
        if ($width > self::MAX_WIDTH) {
            $this->_width = self::MAX_WIDTH;
        } else {
            $this->_width = (int)$width;
        }
    }

    /**
     * Returns the height of the sparkline
     * @return int
     */
    public function getHeight() {
        return $this->_height;
    }

    /**
     * Sets the height of the sparkline
     * @param int $height
     */
    public function setHeight($height) {
        if (!is_numeric($height) || $height <= 0) {
            return;
        }
        if ($height > self::MAX_HEIGHT) {
            $this->_height = self::MAX_HEIGHT;
        } else {
            $this->_height = (int)$height;
        }
    }

    /**
     * Sets the sparkline colors
     *
     * @param \Davaxi\Sparkline $sparkline
     */
    private function setSparklineColors($sparkline, $seriesIndex) {
        $colors = Common::getRequestVar('colors', false, 'json');

        if (empty($colors)) { // quick fix so row evolution sparklines will have color in widgetize's iframes
            $colors = array(
                'backgroundColor' => '#ffffff',
                'lineColor' => '#162C4A',
                'minPointColor' => '#ff7f7f',
                'maxPointColor' => '#75BF7C',
                'lastPointColor' => '#55AAFF',
                'fillColor' => '#ffffff'
            );
        }

        if (strtolower($colors['backgroundColor']) !== '#ffffff') {
            $sparkline->setBackgroundColorHex($colors['backgroundColor']);
        } else {
            $sparkline->deactivateBackgroundColor();
        }

        if (is_array($colors['lineColor'])) {
            $sparkline->setLineColorHex($colors['lineColor'][$seriesIndex], $seriesIndex);

            // set point colors to same as line colors so they can be better differentiated
            $colors['minPointColor'] = $colors['maxPointColor'] = $colors['lastPointColor'] = $colors['lineColor'][$seriesIndex];
        } else {
            $sparkline->setLineColorHex($colors['lineColor']);
        }

        if (strtolower($colors['fillColor'] !== "#ffffff")) {
            $sparkline->setFillColorHex($colors['fillColor']);
        } else {
            $sparkline->deactivateFillColor();
        }
        if (strtolower($colors['minPointColor'] !== "#ffffff")) {
            $sparkline->addPoint("minimum", 5, $colors['minPointColor'], $seriesIndex);
        }
        if (strtolower($colors['maxPointColor'] !== "#ffffff")) {
            $sparkline->addPoint("maximum", 5, $colors['maxPointColor'], $seriesIndex);
        }
        if (strtolower($colors['lastPointColor'] !== "#ffffff")) {
            $sparkline->addPoint("last", 5, $colors['lastPointColor'], $seriesIndex);
        }
    }

    public function render() {
        if ($this->sparkline instanceof \Davaxi\Sparkline) {
            $this->sparkline->display();
            $this->sparkline->destroy();
        }
    }
}
