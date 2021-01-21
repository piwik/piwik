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
 * Filter that will remove columns from a {@link DataTable} using either a blacklist,
 * allowlist or both.
 *
 * This filter is used to handle the **hideColumn** and **showColumn** query parameters.
 *
 * **Basic usage example**
 *
 *     $columnsToRemove = array('nb_hits', 'nb_pageviews');
 *     $dataTable->filter('ColumnDelete', array($columnsToRemove));
 *
 *     $columnsToKeep = array('nb_visits');
 *     $dataTable->filter('ColumnDelete', array(array(), $columnsToKeep));
 *
 * @api
 */
class ColumnDelete extends BaseFilter
{
    /**
     * The columns that should be removed from DataTable rows.
     *
     * @var array
     */
    private $columnsToRemove;

    /**
     * The columns that should be kept in DataTable rows. All other columns will be
     * removed. If a column is in $columnsToRemove and this variable, it will NOT be kept.
     *
     * @var array
     */
    private $columnsToKeep;

    /**
     * Hack: when specifying "showColumns", sometimes we'd like to also keep columns that "look" like a given column,
     * without manually specifying all these columns (which may not be possible if column names are generated dynamically)
     *
     * Column will be kept, if they match any name in the $columnsToKeep, or if they look like anyColumnToKeep__anythingHere
     */
    const APPEND_TO_COLUMN_NAME_TO_KEEP = '__';

    /**
     * Delete the column, only if the value was zero
     *
     * @var bool
     */
    private $deleteIfZeroOnly;

    /**
     * Delete the column recursively in all nested arrays as well
     *
     * @var bool
     */
    private $deleteRecursive;

    /**
     * Constructor.
     *
     * @param DataTable $table The DataTable instance that will eventually be filtered.
     * @param array|string $columnsToRemove An array of column names or a comma-separated list of
     *                                      column names. These columns will be removed.
     * @param array|string $columnsToKeep An array of column names that should be kept or a
     *                                    comma-separated list of column names. Columns not in
     *                                    this list will be removed.
     * @param bool $deleteIfZeroOnly If true, columns will be removed only if their value is 0.
     * @param bool $deleteRecursive If true, columns will be removed in nested arrays.
     */
    public function __construct($table, $columnsToRemove, $columnsToKeep = array(), $deleteIfZeroOnly = false, $deleteRecursive = false)
    {
        parent::__construct($table);

        if (is_string($columnsToRemove)) {
            $columnsToRemove = $columnsToRemove === '' ? array() : explode(',', $columnsToRemove);
        }

        if (is_string($columnsToKeep)) {
            $columnsToKeep = $columnsToKeep === '' ? array() : explode(',', $columnsToKeep);
        }

        $this->columnsToRemove = $columnsToRemove;
        $this->columnsToKeep = array_flip($columnsToKeep); // flip so we can use isset instead of in_array
        $this->deleteIfZeroOnly = $deleteIfZeroOnly;
        $this->deleteRecursive = $deleteRecursive;
    }

    /**
     * See {@link ColumnDelete}.
     *
     * @param DataTable $table
     * @return DataTable
     */
    public function filter($table)
    {
        // always do recursive filter
        $this->enableRecursive(true);
        $recurse = false; // only recurse if there are columns to remove/keep

        // remove columns specified in $this->columnsToRemove
        if (!empty($this->columnsToRemove)) {
            $this->removeColumnsFromTable($table);
            $recurse = true;
        }

        // remove columns not specified in $columnsToKeep
        if (!empty($this->columnsToKeep)) {
            foreach ($table as $index => $row) {
                $columnsToDelete = array();
                foreach ($row as $name => $value) {
                    $keep = false;

                    // @see self::APPEND_TO_COLUMN_NAME_TO_KEEP
                    foreach ($this->columnsToKeep as $nameKeep => $true) {
                        if (strpos($name, $nameKeep . self::APPEND_TO_COLUMN_NAME_TO_KEEP) === 0) {
                            $keep = true;
                        }
                    }

                    if (!$keep
                        && $name !== 'label' // label cannot be removed via allowlisting
                        && !isset($this->columnsToKeep[$name])
                    ) {
                        // we cannot remove row directly to prevent notice "ArrayIterator::next(): Array was modified
                        // outside object and internal position is no longer valid in /var/www..."
                        $columnsToDelete[] = $name;
                    }
                }
                foreach ($columnsToDelete as $columnToDelete) {
                    unset($table[$index][$columnToDelete]);
                }
            }

            $recurse = true;
        }

        // recurse
        if ($recurse && !is_array($table)) {
            foreach ($table as $row) {
                $this->filterSubTable($row);
            }
        }

        return $table;
    }

    /**
     * @param $table
     */
    protected function removeColumnsFromTable(&$table)
    {
        if (!$this->isArrayAccess($table)) {
            return;
        }

        foreach ($table as $index => $row) {
            if (!$this->isArrayAccess($row)) {
                continue;
            }

            foreach ($this->columnsToRemove as $column) {
                if (is_array($row)) {
                    if (!array_key_exists($column, $row)) {
                        continue;
                    }
                } elseif ($row instanceof \ArrayAccess) {
                    if (!$row->offsetExists($column)) {
                        continue;
                    }
                }

                if ($this->deleteIfZeroOnly) {
                    $value = $row[$column];
                    if ($value === false || !empty($value)) {
                        continue;
                    }
                }

                unset($table[$index][$column]);
            }

            if ($this->deleteRecursive) {
                $this->removeColumnsFromTable($table[$index]);
            }
        }
    }

    /**
     * @param $table
     * @return bool
     */
    protected function isArrayAccess(&$table)
    {
        return is_array($table) || $table instanceof \ArrayAccess;
    }
}
