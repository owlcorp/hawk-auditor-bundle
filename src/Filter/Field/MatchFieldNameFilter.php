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

namespace OwlCorp\HawkAuditor\Filter\Field;

use OwlCorp\HawkAuditor\Exception\InvalidArgumentException;
use OwlCorp\HawkAuditor\Filter\CacheableFilter;
use OwlCorp\HawkAuditor\Filter\FieldNameFilter;
use OwlCorp\HawkAuditor\Type\OperationType;

/**
 *
 * @phpstan-type TEmptyString string
 * @phpstan-type TClassName class-string
 * @phpstan-type TFieldName string
 * @phpstan-type TLookupKey string
 * For explanation of types see createLookupIndex()
 */
final class MatchFieldNameFilter implements FieldNameFilter, CacheableFilter
{
    /** @param array<TLookupKey, true> $typesFieldsIndex */
    private function __construct(private array $typesFieldsIndex, private bool $onMatch, private ?bool $onNonMatch)
    {
    }

    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter -- interface declaration conformity
    public function isFieldAuditable(OperationType $opType, string $entityClass, string $fieldName): ?bool
    {
        //Arranged from most to least common. The 3rd scenario is really... strange but supported
        return isset($this->typesFieldsIndex[$fieldName]) ||
               isset($this->typesFieldsIndex[$entityClass . '|' . $fieldName]) ||
               isset($this->typesFieldsIndex[$entityClass]) ? $this->onMatch : $this->onNonMatch;
    }

    /**
     * Creates a filter that makes the final decision to include all matching fields and excluding all non-matching ones
     *
     * This filter has an effect of including only entities fields listed in the index to be audited, while excluding
     * all other ones which are not listed here. Any other type filters with lower priority will NOT be called and the
     * answer will be cached. Due to the nature of the $index some decisions are made based on class+field, while other
     * are based on field name only (for empty/wildcard class). See createLookupIndex() for explanation.
     * This is the implementation for "only_include_fields" configuration.
     *
     * @param array<TLookupKey, true> $index Fast-match index, see createLookupIndex()
     */
    public static function includeOnMatchExcludeOtherwise(array $index): self
    {
        return new self(typesFieldsIndex: $index, onMatch: true, onNonMatch: false);
    }

    /**
     * Creates a filter that makes the final decision to exclude all matching fields and including all non-matching ones
     *
     * This filter has an effect of excluding only entities fields listed in the index from being audited, while
     * including all other ones which are not listed here. Any other type filters with lower priority will NOT be called
     * and the answer will be cached. Due to the nature of the $index some decisions are made based on class+field,
     * while other are based on field name only (for empty/wildcard class). See createLookupIndex() for explanation.
     * This is the implementation for "only_exclude_fields" configuration.
     *
     * @param array<TLookupKey, true> $index Fast-match index, see createLookupIndex()
     */
    public static function excludeOnMatchIncludeOtherwise(array $index): self
    {
        return new self(typesFieldsIndex: $index, onMatch: false, onNonMatch: true);
    }

    /**
     * Creates a filter that makes a decision to include matching fields, while ignoring all other ones
     *
     * This filter has an effect of including all entities fields listed in the index to be audited, while casting an
     * "abstain" vote for types not present on the list. This causes the decision to be passed to a next filter in
     * chain. Due to the nature of the $index some decisions are made based on class+field, while other are based on
     * field name only (for empty/wildcard class). See createLookupIndex() for explanation.
     * This is the implementation for "include_fields" configuration.
     *
     * @param array<TLookupKey, true> $index Fast-match index, see createLookupIndex()
     */
    public static function includeOnMatchAbstainOtherwise(array $index): self
    {
        return new self(typesFieldsIndex: $index, onMatch: true, onNonMatch: null);
    }

    /**
     * Creates a filter that makes a decision to exclude matching fields, while ignoring all other ones
     *
     * This filter has an effect of excluding all entities fields listed in the index from being audited, while casting
     * an "abstain" vote for types not present on the list. This causes the decision to be passed to a next filter in
     * chain. Due to the nature of the $index some decisions are made based on class+field, while other
     * are based on field name only (for empty/wildcard class). See createLookupIndex() for explanation.
     * This is the implementation for "exclude_fields" configuration.
     *
     * @param array<TLookupKey, true> $index Fast-match index, see createLookupIndex()
     */
    public static function excludeOnMatchAbstainOtherwise(array $index): self
    {
        return new self(typesFieldsIndex: $index, onMatch: false, onNonMatch: null);
    }

    /**
     * Converts a typical format of a tree with classes and corresponding fields to a fast lookup index
     *
     * This method is meant to be used during compilation of the app (e.g. in DIC) with the result being precached. This
     * is why it is not integrated into factory methods (i.e. we want to do this conversion once and not on runtime).
     *
     * @param array<TEmptyString, non-empty-list<TFieldName>>|array<TClassName, list<TFieldName>|null> $tree
     *
     * @return array<TLookupKey, true>
     */
    public static function createLookupIndex(array $tree): array
    {
        $index = [];
        foreach ($tree as $fqcnOrEmpty => $fields) {
            $hasFqcn = $fqcnOrEmpty !== ''; //You cannot have nulls as keys in arrays, so that's why empty string
            $hasFields = $fields !== null && \count($fields) > 0;

            if (!$hasFqcn && !$hasFields) {
                throw new InvalidArgumentException(
                    \sprintf(
                        'You cannot use a %s filter with no type and no field name - you need at least one',
                        self::class
                    )
                );
            }

            if (!$hasFields) { //type only (weird scenario but it's supported)
                $index[$fqcnOrEmpty] = true;
                continue;
            }


            foreach ($fields as $fieldName) {
                $fieldName = (string)$fieldName;
                if ($fieldName === '') {
                    throw new InvalidArgumentException(
                        \sprintf(
                            'You cannot use a %s filter with empty field names (found for "%s" type). You can only ' .
                            'use it with empty type names (indicating wildcard type), or types with no fields ' .
                            'defined (indicating wildcard field name for type).',
                            self::class,
                            $fqcnOrEmpty
                        )
                    );
                }

                $key = $hasFqcn ? $fieldName : ($fqcnOrEmpty . '|' . $fieldName);
                $index[$key] = true;
            }
        }

        return $index;
    }
}
