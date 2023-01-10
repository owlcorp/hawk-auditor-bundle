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

namespace OwlCorp\HawkAuditor\Processor;

use OwlCorp\HawkAuditor\DependencyInjection\FilterAwareProcessor;
use OwlCorp\HawkAuditor\DTO\Changeset;
use OwlCorp\HawkAuditor\Filter\FilterProvider;
use OwlCorp\HawkAuditor\Type\OperationType;
use Symfony\Contracts\Cache\CacheInterface;

final class FilteredProcessor implements AuditProcessor, FilterAwareProcessor
{
    /**
     * @var array<string, bool|null> Cache of EntityTypeFilter responses valid for the current UoW only
     */
    private array $jitTypesCache = [];

    /**
     * @var array<string, bool>  Persisted cache of EntityTypeFilter responses valid indefinitely
     */
    private array $level2TypesCache = [];

    /**
     * @var array<string, array<string, bool>>  Persisted cache of FieldNameFilter responses valid indefinitely
     */
    private array $level2FieldsCache = [];

    public function __construct(
        private FilterProvider $filterProvider,
        private bool $defaultAuditType,
        private bool $defaultAuditField,
        private ?CacheInterface $cache = null
    ) {
    }

    public function isTypeAuditable(OperationType $opType, string $entityClass): bool
    {
        $cacheKey = $opType->value . '|' . $entityClass; //this is more performant than 2D array
        if (isset($this->jitTypesCache[$cacheKey])) { //note: isset() is intentional here as we DO NOT cache null answer
            return $this->jitTypesCache[$cacheKey];
        }

        //todo: add cache call here
        if (isset($this->level2TypesCache[$cacheKey])) {
            return $this->level2TypesCache[$cacheKey];
        }

        foreach ($this->filterProvider->getEntityTypeFilters() as $filter) {
            $shouldAudit = $filter->isTypeAuditable($opType, $entityClass);
            $this->jitTypesCache[$cacheKey] = $shouldAudit; //always cache short-term (at least this is the idea now)

            if ($shouldAudit === null) {
                continue;
            }

            if (isset($this->filterProvider->cacheableFilters[$filter::class])) { //cold-cache when filter supports it
                $this->level2TypesCache[$cacheKey] = $shouldAudit;
            }

            return $shouldAudit;
        }

        return $this->defaultAuditType;
    }

    public function sealChangeset(Changeset $changeset): bool
    {
        $this->filterFields($changeset);
        $sealed = $this->filterChangeset($changeset);

        //since the changeset is sealed it will be flushed, so we should drop JIT (per-flush) types cache
        $this->jitTypesCache = [];

        return $sealed;
    }

    /**
     * Filters fields to be potentially excluded from entities changesets
     *
     * Calling this method is expensive as we need to iterate through every field of every entity at worst twice (old +
     * new state). Even if every filtered field ends up with a default answer (most likely "include it") it will be a
     * waste of time to iterate over it. If there are no defined for fields this whole process should be skipped. This
     * isn't an issue when at least one field filter is defined, as the field list has to be iterated over, even if the
     * filter is cacheable (assuming that the filter isn't simply returning "true" for everything which would be a
     * really unrealistic scenario).
     * Another trap here is all filtering services are lazy-created by the container. Thus, we cannot just get filters
     * list willy-nilly just to check the count as this will initialize all of them for no reason. This is why this
     * method has a special exception in FilterProvider to provide a count of this type of filters which is a compile-in
     * value from the container.
     * Overall, this method was profiled and optimized. Thus, it's code isn't pretty but should be straight-forward to
     * follow. While it can be split into smaller chunks and avoid excessive nesting, this hurts performance quite a bit
     * with larger datasets. This is a consequence of PHPs inability to inline functions, except a small subset of
     * built-in ones (https://php.watch/articles/php-zend-engine-special-inlined-functions).
     *
     * This is technically a problem for type filters too, but there's very few calls for these and types will be static
     * in most systems. In other words, this isn't a performance problem in other scenarios.
     */
    //phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- performance-critical code
    private function filterFields(Changeset $changeset): void
    {
        if (!$this->filterProvider->hasFieldNameFilters) {
            return; //see the method note
        }

        //If there's at least one FieldNameFilter we must iterate over the whole fieldset, even if all fields were
        // cached in L2.
        $jitFieldsCache = []; //Since we run this once per flush JIT cache shouldn't leak outside of this method
        $filters = null;
        foreach ($changeset->getAllEntities() as $record) {
            $classCacheKey = $record->type->value . '|' . $record->entityClass; //this is more performant than 3D array


            foreach (\array_keys($record->stateChange) as $field) { //faster than value discard
                if (isset($jitFieldsCache[$classCacheKey][$field])) { //found in JIT
                    if (!$jitFieldsCache[$classCacheKey][$field]) { //unset when asked otherwise noop
                        unset($record->stateChange[$field]);
                    }
                    continue;
                }

                //This block is almost exactly the same as the one for JIT above
                if (isset($this->level2FieldsCache[$classCacheKey][$field])) {
                    if (!$this->level2FieldsCache[$classCacheKey][$field]) {
                        unset($record->stateChange[$field]);
                    }
                    continue;
                }

                //No caches have it => ask filters
                $filters ??= $this->filterProvider->getFieldNameFilters();
                foreach ($filters as $filter) {
                    $shouldAudit = $filter->isFieldAuditable($record->type, $record->entityClass, $field);
                    if ($shouldAudit === false) {
                        unset($record->stateChange[$field]);
                    }

                    if ($shouldAudit !== null && isset($this->filterProvider->cacheableFilters[$filter::class])) {
                        $jitFieldsCache[$classCacheKey][$field] = $shouldAudit;
                        //todo add some canary to update L2 after all ops are completed
                    }
                }

                //if by default fields are removed respect that BUT don't cache as non-cacheable field filters
                // aren't guaranteed to be deterministic between flushes
                if (!$this->defaultAuditField) {
                    unset($record->stateChange[$field]);
                }
            }
        }
    }

    private function filterChangeset(Changeset $changeset): bool
    {
        foreach ($this->filterProvider->getChangesetFilters() as $filter) {
            if (!$filter->onAudit($changeset)) {
                return false;
            }
        }

        //By default, we keep changeset. There's no "defaultAuditChangeset" parameter as this would mean, if set to
        // false, that the whole changeset is always constructed just to be thrown away. To achieve this setting the
        // $defaultAuditType=false will achieve the same thing but much faster/earlier.
        return true;
    }
}
