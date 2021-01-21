<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Columns;

use Piwik\Plugin\Segment;


/**
 * A factory to create segments from a dimension.
 *
 * @api since Matomo 4.0.0
 */
class DimensionSegmentFactory
{
    /**
     * @var Dimension
     */
    private $dimension = null;

    /**
     * Generates a new dimension segment factory.
     * @param Dimension $dimension A dimension instance the created segments should be based on.
     */
    public function __construct(Dimension $dimension)
    {
        $this->dimension = $dimension;
    }

    /**
     * Creates a segment based on the dimension properties
     *
     * @param Segment|null $segment optional Segment to enrich with dimension data (if properties not already set)
     * @return Segment
     * @throws \Exception
     */
    public function createSegment(Segment $segment = null)
    {
        $dimension = $this->dimension;

        if (!$segment instanceof Segment) {
            $segment = new Segment();
        }

        if (!$segment->getSegment() && $dimension->getSegmentName()) {
            $segment->setSegment($dimension->getSegmentName());
        }

        if (!$segment->getType()) {
            $metricTypes = array(Dimension::TYPE_NUMBER, Dimension::TYPE_FLOAT, Dimension::TYPE_MONEY, Dimension::TYPE_DURATION_S, Dimension::TYPE_DURATION_MS);
            if (in_array($dimension->getType(), $metricTypes, $strict = true)) {
                $segment->setType(Segment::TYPE_METRIC);
            } else {
                $segment->setType(Segment::TYPE_DIMENSION);
            }
        }

        if (!$segment->getCategoryId() && $dimension->getCategoryId()) {
            $segment->setCategory($dimension->getCategoryId());
        }

        if (!$segment->getName() && $dimension->getName()) {
            $segment->setName($dimension->getName());
        }

        $sqlSegment = $segment->getSqlSegment();

        if (empty($sqlSegment) && !$segment->getUnionOfSegments()) {
            if (!empty($dimension->getSqlSegment())) {
                $segment->setSqlSegment($dimension->getSqlSegment());
            } elseif ($dimension->getDbTableName() && $dimension->getColumnName()) {
                $segment->setSqlSegment($dimension->getDbTableName() . '.' . $dimension->getColumnName());
            } else {
                throw new \Exception('Segment cannot be added because no sql segment is set');
            }
        }

        $acceptValues = $dimension->getAcceptValues() ?: $this->guessAcceptValues();

        if ($acceptValues && !$segment->getAcceptValues()) {
            $segment->setAcceptedValues($acceptValues);
        }

        $suggestedValuesCallback = $dimension->getSuggestedValuesCallback() ?: $this->guessSuggestedValuesCallback();

        if ($suggestedValuesCallback && !$segment->getSuggestedValuesCallback()) {
            $segment->setSuggestedValuesCallback($suggestedValuesCallback);
        }

        if ($dimension->getSuggestedValuesApi()) {
            $segment->setSuggestedValuesApi($dimension->getSuggestedValuesApi());
        }

        $sqlFilter = $dimension->getSqlFilter();

        if (!$sqlFilter && !$dimension->getSqlFilterValue() && !$segment->getSqlFilter() && !$segment->getSqlFilterValue()) {
            $sqlFilter = $this->guessSqlFilter();
        }

        if ($dimension->getSqlFilterValue() && !$segment->getSqlFilterValue()) {
            $segment->setSqlFilterValue($dimension->getSqlFilterValue());
        }

        if ($sqlFilter && !$segment->getSqlFilter()) {
            $segment->setSqlFilter($sqlFilter);
        }

        if (!$dimension->isAnonymousAllowed()) {
            $segment->setRequiresRegisteredUser(true);
        }

        $segment->setRequiresProfilableData($dimension->isRequiresProfilableData());

        return $segment;
    }

    protected function guessSqlFilter()
    {
        $sqlFilterValue = null;

        $enum = $this->dimension->getEnumColumnValues();
        if (!empty($enum)) {
            $sqlFilterValue = function ($value, $sqlSegmentName) use ($enum) {
                if (isset($enum[$value])) {
                    return $value;
                }

                $id = array_search($value, $enum);

                if ($id === false) {
                    $id = array_search(strtolower(trim(urldecode($value))), $enum);

                    if ($id === false) {
                        throw new \Exception("Invalid '$sqlSegmentName' segment value $value");
                    }
                }

                return $id;
            };
        }

        return $sqlFilterValue;
    }

    protected function guessAcceptValues()
    {
        $acceptValues = null;

        // we can generate accept values for enums automatically
        $enum = $this->dimension->getEnumColumnValues();
        if (!empty($enum)) {
            $enumValues = array_values($enum);
            $enumValues = array_slice($enumValues, 0, 20);
            $acceptValues = 'Eg. ' . implode(', ', $enumValues);
        }

        return $acceptValues;
    }

    protected function guessSuggestedValuesCallback()
    {
        $suggestedValuesCallback = null;

        // we can generate efficient value callback for enums automatically
        $enum = $this->dimension->getEnumColumnValues();
        if (!empty($enum)) {
            $suggestedValuesCallback = function ($idSite, $maxValuesToReturn) use ($enum) {
                $values = array_values($enum);
                return array_slice($values, 0, $maxValuesToReturn);
            };
        }

        return $suggestedValuesCallback;
    }
}