<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Archive;

use Exception;
use Piwik\DataTable;

/**
 * This class is used to hold and transform archive data for the Archive class.
 *
 * Archive data is loaded into an instance of this type, can be indexed by archive
 * metadata (such as the site ID, period string, etc.), and can be transformed into
 * DataTable and Map instances.
 */
class DataCollection
{
    const METADATA_CONTAINER_ROW_KEY = '_metadata';

    /**
     * The archive data, indexed first by site ID and then by period date range. Eg,
     *
     * array(
     *     '0' => array(
     *         array(
     *             '2012-01-01,2012-01-01' => array(...),
     *             '2012-01-02,2012-01-02' => array(...),
     *         )
     *     ),
     *     '1' => array(
     *         array(
     *             '2012-01-01,2012-01-01' => array(...),
     *         )
     *     )
     * )
     *
     * Archive data can be either a numeric value or a serialized string blob. Every
     * piece of archive data is associated by it's archive name. For example,
     * the array(...) above could look like:
     *
     * array(
     *    'nb_visits' => 1,
     *    'nb_actions' => 2
     * )
     *
     * There is a special element '_metadata' in data rows that holds values treated
     * as DataTable metadata.
     */
    private $data = array();

    /**
     * The whole list of metric/record names that were used in the archive query.
     *
     * @var array
     */
    private $dataNames;

    /**
     * The type of data that was queried for (ie, "blob" or "numeric").
     *
     * @var string
     */
    private $dataType;

    /**
     * The default values to use for each metric/record name that's being queried
     * for.
     *
     * @var array
     */
    private $defaultRow;

    /**
     * The list of all site IDs that were queried for.
     *
     * @var array
     */
    private $sitesId;

    /**
     * The list of all periods that were queried for. Each period is associated with
     * the period's range string. Eg,
     *
     * array(
     *     '2012-01-01,2012-01-31' => new Period(...),
     *     '2012-02-01,2012-02-28' => new Period(...),
     * )
     *
     * @var \Piwik\Period[]
     */
    private $periods;

    /**
     * Constructor.
     *
     * @param array $dataNames @see $this->dataNames
     * @param string $dataType @see $this->dataType
     * @param array $sitesId @see $this->sitesId
     * @param \Piwik\Period[] $periods @see $this->periods
     * @param array $defaultRow @see $this->defaultRow
     */
    public function __construct($dataNames, $dataType, $sitesId, $periods, $segment, $defaultRow = null)
    {
        $this->dataNames = $dataNames;
        $this->dataType = $dataType;

        if ($defaultRow === null) {
            $defaultRow = array_fill_keys($dataNames, 0);
            unset($defaultRow['nb_profilable']); // for this special metric, we want to know when it was never present
        }

        $this->sitesId = $sitesId;

        foreach ($periods as $period) {
            $this->periods[$period->getRangeString()] = $period;
        }

        $this->segment = $segment;
        $this->defaultRow = $defaultRow;
    }

    /**
     * Returns a reference to the data for a specific site & period. If there is
     * no data for the given site ID & period, it is set to the default row.
     *
     * @param int $idSite
     * @param string $period eg, '2012-01-01,2012-01-31'
     */
    public function &get($idSite, $period)
    {
        if (!isset($this->data[$idSite][$period])) {
            $this->data[$idSite][$period] = $this->defaultRow;
        }
        return $this->data[$idSite][$period];
    }

    /**
     * Set data for a specific site & period. If there is no data for the given site ID & period,
     * it is set to the default row.
     *
     * @param int $idSite
     * @param string $period eg, '2012-01-01,2012-01-31'
     * @param string $name eg 'nb_visits'
     * @param string $value eg 5
     */
    public function set($idSite, $period, $name, $value)
    {
        $row = & $this->get($idSite, $period);
        $row[$name] = $value;
    }

    /**
     * Adds a new metadata to the data for specific site & period. If there is no
     * data for the given site ID & period, it is set to the default row.
     *
     * Note: Site ID and period range string are two special types of metadata. Since
     * the data stored in this class is indexed by site & period, this metadata is not
     * stored in individual data rows.
     *
     * @param int $idSite
     * @param string $period eg, '2012-01-01,2012-01-31'
     * @param string $name The metadata name.
     * @param mixed $value The metadata name.
     */
    public function addMetadata($idSite, $period, $name, $value)
    {
        $row = & $this->get($idSite, $period);
        $row[self::METADATA_CONTAINER_ROW_KEY][$name] = $value;
    }

    /**
     * Returns archive data as an array indexed by metadata.
     *
     * @param array $resultIndices An array mapping metadata names to pretty labels
     *                             for them. Each archive data row will be indexed
     *                             by the metadata specified here.
     *
     *                             Eg, array('site' => 'idSite', 'period' => 'Date')
     * @return array
     */
    public function getIndexedArray($resultIndices)
    {
        $indexKeys = array_keys($resultIndices);

        $result = $this->createOrderedIndex($indexKeys);
        foreach ($this->data as $idSite => $rowsByPeriod) {
            foreach ($rowsByPeriod as $period => $row) {
                // FIXME: This hack works around a strange bug that occurs when getting
                //         archive IDs through ArchiveProcessing instances. When a table
                //         does not already exist, for some reason the archive ID for
                //         today (or from two days ago) will be added to the Archive
                //         instances list. The Archive instance will then select data
                //         for periods outside of the requested set.
                //         working around the bug here, but ideally, we need to figure
                //         out why incorrect idarchives are being selected.
                if (empty($this->periods[$period])) {
                    continue;
                }

                $this->putRowInIndex($result, $indexKeys, $row, $idSite, $period);
            }
        }

        return $result;
    }

    /**
     * Returns archive data as a DataTable indexed by metadata. Indexed data will
     * be represented by Map instances.
     *
     * @param array $resultIndices An array mapping metadata names to pretty labels
     *                             for them. Each archive data row will be indexed
     *                             by the metadata specified here.
     *
     *                             Eg, array('site' => 'idSite', 'period' => 'Date')
     * @return DataTable|DataTable\Map
     */
    public function getDataTable($resultIndices)
    {
        $dataTableFactory = new DataTableFactory(
            $this->dataNames, $this->dataType, $this->sitesId, $this->periods, $this->segment, $this->defaultRow);

        $index = $this->getIndexedArray($resultIndices);

        return $dataTableFactory->make($index, $resultIndices);
    }

    /**
     * See {@link DataTableFactory::makeMerged()}
     *
     * @param array $resultIndices
     * @return DataTable|DataTable\Map
     * @throws Exception
     */
    public function getMergedDataTable($resultIndices)
    {
        $dataTableFactory = new DataTableFactory(
            $this->dataNames, $this->dataType, $this->sitesId, $this->periods, $this->segment, $this->defaultRow);

        $index = $this->getIndexedArray($resultIndices);

        return $dataTableFactory->makeMerged($index, $resultIndices);
    }

    /**
     * Returns archive data as a DataTable indexed by metadata. Indexed data will
     * be represented by Map instances. Each DataTable will have
     * its subtable IDs set.
     *
     * This function will only work if blob data was loaded and only one record
     * was loaded (not including subtables of the record).
     *
     * @param array $resultIndices An array mapping metadata names to pretty labels
     *                             for them. Each archive data row will be indexed
     *                             by the metadata specified here.
     *
     *                             Eg, array('site' => 'idSite', 'period' => 'Date')
     * @param int|null $idSubTable The subtable to return.
     * @param int|null $depth max depth for subtables.
     * @param bool $addMetadataSubTableId Whether to add the DB subtable ID as metadata
     *                                    to each datatable, or not.
     * @throws Exception
     * @return DataTable|DataTable\Map
     */
    public function getExpandedDataTable($resultIndices, $idSubTable = null, $depth = null, $addMetadataSubTableId = false)
    {
        if ($this->dataType != 'blob') {
            throw new Exception("DataCollection: cannot call getExpandedDataTable with "
                . "{$this->dataType} data types. Only works with blob data.");
        }

        if (count($this->dataNames) !== 1) {
            throw new Exception("DataCollection: cannot call getExpandedDataTable with "
                . "more than one record.");
        }

        $dataTableFactory = new DataTableFactory(
            $this->dataNames, 'blob', $this->sitesId, $this->periods, $this->segment, $this->defaultRow);
        $dataTableFactory->expandDataTable($depth, $addMetadataSubTableId);
        $dataTableFactory->useSubtable($idSubTable);

        $index = $this->getIndexedArray($resultIndices);

        return $dataTableFactory->make($index, $resultIndices);
    }

    /**
     * Returns metadata for a data row.
     *
     * @param array $data The data row.
     * @return array
     */
    public static function getDataRowMetadata($data)
    {
        if (isset($data[self::METADATA_CONTAINER_ROW_KEY])) {
            return $data[self::METADATA_CONTAINER_ROW_KEY];
        } else {
            return array();
        }
    }

    /**
     * Removes all table metadata from a data row.
     *
     * @param array $data The data row.
     */
    public static function removeMetadataFromDataRow(&$data)
    {
        unset($data[self::METADATA_CONTAINER_ROW_KEY]);
    }

    /**
     * Creates an empty index using a list of metadata names. If the 'site' and/or
     * 'period' metadata names are supplied, empty rows are added for every site/period
     * that was queried for.
     *
     * Using this function ensures consistent ordering in the indexed result.
     *
     * @param array $metadataNamesToIndexBy List of metadata names to index archive data by.
     * @return array
     */
    private function createOrderedIndex($metadataNamesToIndexBy)
    {
        $result = array();

        if (!empty($metadataNamesToIndexBy)) {
            $metadataName = array_shift($metadataNamesToIndexBy);

            if ($metadataName == DataTableFactory::TABLE_METADATA_SITE_INDEX) {
                $indexKeyValues = array_values($this->sitesId);
            } elseif ($metadataName == DataTableFactory::TABLE_METADATA_PERIOD_INDEX) {
                $indexKeyValues = array_keys($this->periods);
            }

            if (empty($metadataNamesToIndexBy)) {
                $result = array_fill_keys($indexKeyValues, array());
            } else {
                foreach ($indexKeyValues as $key) {
                    $result[$key] = $this->createOrderedIndex($metadataNamesToIndexBy);
                }
            }
        }

        return $result;
    }

    /**
     * Puts an archive data row in an index.
     */
    private function putRowInIndex(&$index, $metadataNamesToIndexBy, $row, $idSite, $period)
    {
        $currentLevel = & $index;

        foreach ($metadataNamesToIndexBy as $metadataName) {
            if ($metadataName == DataTableFactory::TABLE_METADATA_SITE_INDEX) {
                $key = $idSite;
            } elseif ($metadataName == DataTableFactory::TABLE_METADATA_PERIOD_INDEX) {
                $key = $period;
            } else {
                $key = $row[self::METADATA_CONTAINER_ROW_KEY][$metadataName];
            }

            if (!isset($currentLevel[$key])) {
                $currentLevel[$key] = array();
            }

            $currentLevel = & $currentLevel[$key];
        }

        $currentLevel = $row;
    }
}
