<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile ElasticSuite to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile\ElasticsuiteCore
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2018 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */

namespace Smile\ElasticsuiteCore\Search\Request\Aggregation;

use Smile\ElasticsuiteCore\Api\Index\Mapping\FieldInterface;
use Smile\ElasticsuiteCore\Search\Request\BucketInterface;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\Filter\QueryBuilder;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;

/**
 * Build aggregation from the mapping.
 *
 * @category Smile
 * @package  Smile\ElasticsuiteCore
 * @author   Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class AggregationBuilder
{
    /**
     * @var AggregationFactory
     */
    private $aggregationFactory;

    /**
     * @var QueryBuilder
     */
    private $queryBuilder;

    /**
     * @var MetricFactory
     */
    private $metricFactory;

    /**
     * Constructor.
     *
     * @param AggregationFactory $aggregationFactory Factory used to instantiate buckets.
     * @param MetricFactory      $metricFactory      Factory used to instantiate metrics.
     * @param QueryBuilder       $queryBuilder       Factory used to create queries inside filtered or nested aggs.
     */
    public function __construct(
        AggregationFactory $aggregationFactory,
        MetricFactory $metricFactory,
        QueryBuilder $queryBuilder
    ) {
        $this->aggregationFactory = $aggregationFactory;
        $this->metricFactory      = $metricFactory;
        $this->queryBuilder       = $queryBuilder;
    }

    /**
     * Build the list of buckets from the mapping.
     *
     * @param ContainerConfigurationInterface $containerConfig Search request configuration
     * @param array                           $aggregations    Facet definitions.
     * @param array                           $filters         Facet filters to be added to buckets.
     *
     * @return BucketInterface[]
     */
    public function buildAggregations(ContainerConfigurationInterface $containerConfig, array $aggregations, array $filters)
    {
        $buckets = [];

        foreach ($aggregations as $aggParams) {
            $buckets[] = is_object($aggParams) ? $aggParams : $this->buildAggregation($containerConfig, $filters, $aggParams);
        }

        return array_filter($buckets);
    }

    /**
     * Build a single aggregation.
     *
     * @param ContainerConfigurationInterface $containerConfig Search request configuration
     * @param array                           $filters         Facet filters to be added to buckets.
     * @param array                           $bucketParams    Current bucket params.
     *
     * @return \Smile\ElasticsuiteCore\Search\Request\BucketInterface
     */
    private function buildAggregation(ContainerConfigurationInterface $containerConfig, $filters, $bucketParams)
    {
        $bucketType = $bucketParams['type'];
        $fieldName  = isset($bucketParams['field']) ? $bucketParams['field'] : $bucketParams['name'];

        try {
            $field = $containerConfig->getMapping()->getField($fieldName);
            $bucketParams['field'] = $field->getMappingProperty(FieldInterface::ANALYZER_UNTOUCHED);
            if ($field->isNested()) {
                $bucketParams['nestedPath'] = $field->getNestedPath();
            } elseif (isset($bucketParams['nestedPath'])) {
                unset($bucketParams['nestedPath']);
            }
        } catch (\Exception $e) {
            $bucketParams['field'] = $fieldName;
        }

        $bucketFilters = array_diff_key($filters, [$fieldName => true]);
        if (!empty($bucketFilters)) {
            $bucketParams['filter'] = $this->createFilter($containerConfig, $bucketFilters);
        }

        if (isset($bucketParams['metrics'])) {
            foreach ($bucketParams['metrics'] as &$metricParam) {
                $metricParam = $this->metricFactory->create($metricParam);
            }
        }

        $bucketParams['childBuckets'] = $this->buildAggregations($containerConfig, $bucketParams['childBuckets'] ?? [], []);

        if (isset($bucketParams['nestedFilter'])) {
            $nestedFilter = $this->createFilter($containerConfig, $bucketParams['nestedFilter'], $bucketParams['nestedPath']);
            $bucketParams['nestedFilter'] = $nestedFilter;
        }

        return $this->aggregationFactory->create($bucketType, $bucketParams);
    }

    /**
     * Create a QueryInterface for a filter using the query builder.
     *
     * @param ContainerConfigurationInterface $containerConfig Search container configuration
     * @param array                           $filters         Filters definition.
     * @param string|null                     $currentPath     Current nested path or null.
     *
     * @return QueryInterface
     */
    private function createFilter(ContainerConfigurationInterface $containerConfig, array $filters, $currentPath = null)
    {
        return $this->queryBuilder->create($containerConfig, $filters, $currentPath);
    }
}
