<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\DataTable\Filter;

use Piwik\DataTable;
use Piwik\DataTable\BaseFilter;

/**
 * Check range
 *
 */
class RangeCheck extends BaseFilter
{
    public static $minimumValue = 0.00;
    public static $maximumValue = 100.0;

    /**
     * @param DataTable $table
     * @param string $columnToFilter name of the column to filter
     * @param float $minimumValue minimum value for range
     * @param float $maximumValue maximum value for range
     */
    public function __construct($table, $columnToFilter, $minimumValue = 0.00, $maximumValue = 100.0)
    {
        parent::__construct($table);

        $this->columnToFilter = $columnToFilter;

        if ((float) $minimumValue < (float) $maximumValue) {
            self::$minimumValue = $minimumValue;
            self::$maximumValue = $maximumValue;
        }
    }

    /**
     * Executes the filter an adjusts all columns to fit the defined range
     *
     * @param DataTable $table
     */
    public function filter($table)
    {
        foreach ($table->getRows() as $row) {
            $value = $row->getColumn($this->columnToFilter);

            if ($value === false) {
                $value = $row->getMetadata($this->columnToFilter);
                if ($value !== false) {
                    if ((float) $value < (float) self::$minimumValue) {
                        $row->setMetadata($this->columnToFilter, self::$minimumValue);
                    } elseif ((float) $value > (float) self::$maximumValue) {
                        $row->setMetadata($this->columnToFilter, self::$maximumValue);
                    }
                }
                continue;
            }

            if ($value !== false) {
                if ((float) $value < (float) self::$minimumValue) {
                    $row->setColumn($this->columnToFilter, self::$minimumValue);
                } elseif ((float) $value > (float) self::$maximumValue) {
                    $row->setColumn($this->columnToFilter, self::$maximumValue);
                }
            }
        }
    }
}
