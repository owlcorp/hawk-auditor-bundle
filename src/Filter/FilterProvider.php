<?php
declare(strict_types=1);
/**
 * This file is part of OwlCorp/HawkAuditor released under GPLv2.
 *
 * Copyright (c) Gregory Zdanowski-House
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace OwlCorp\HawkAuditor\Filter;

/**
 * @internal
 * @readonly
 */
final class FilterProvider
{
    /**
     * @var array<class-string<CacheableFilter>, true>
     * @readonly it shouldn't be modified externally
     */
    public array $cacheableFilters = [];

    /** @var list<EntityTypeFilter> */
    private array $entityTypeFilters;

    /** @var list<FieldNameFilter> */
    private array $fieldNameFilters;

    /** @var list<ChangesetFilter> */
    private array $changesetFilters;

    /**
     * @param iterable<EntityTypeFilter> $entityTypeFiltersIt
     * @param iterable<FieldNameFilter> $fieldNameFiltersIt
     * @param iterable<ChangesetFilter> $changesetFiltersIt
     */
    public function __construct(
        private iterable $entityTypeFiltersIt,
        private iterable $fieldNameFiltersIt,
        private iterable $changesetFiltersIt,
        //See FilteredStrategy::filterFields for explanation of this field
        public readonly bool $hasFieldNameFilters
    ) {
    }

    /** @return list<ChangesetFilter> */
    public function getChangesetFilters(): iterable
    {
        if (!isset($this->changesetFilters)) {
            $this->changesetFilters = [];
            foreach ($this->changesetFiltersIt as $filter) {
                //this type of filter doesn't support cacheable as this makes no sense
                $this->changesetFilters[] = $filter;
            }
        }

        return $this->changesetFilters;
    }

    /** @return list<EntityTypeFilter> */
    public function getEntityTypeFilters(): iterable
    {
        if (isset($this->entityTypeFilters)) {
            return $this->entityTypeFilters;
        }

        $this->entityTypeFilters = [];
        foreach ($this->entityTypeFiltersIt as $filter) {
            $this->entityTypeFilters[] = $filter;
            if ($filter instanceof CacheableFilter) {
                $this->cacheableFilters[$filter::class] = true;
            }
        }

        return $this->entityTypeFilters;
    }

    /** @return list<FieldNameFilter> */
    public function getFieldNameFilters(): iterable
    {
        if (isset($this->fieldNameFilters)) {
            return $this->fieldNameFilters;
        }

        $this->fieldNameFilters = [];
        foreach ($this->fieldNameFiltersIt as $filter) {
            $this->fieldNameFilters[] = $filter;
            if ($filter instanceof CacheableFilter) {
                $this->cacheableFilters[$filter::class] = true;
            }
        }

        return $this->fieldNameFilters;
    }
}
