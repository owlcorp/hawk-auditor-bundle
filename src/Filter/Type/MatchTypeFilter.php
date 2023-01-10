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

namespace OwlCorp\HawkAuditor\Filter\Type;

use OwlCorp\HawkAuditor\Exception\InvalidArgumentException;
use OwlCorp\HawkAuditor\Filter\CacheableFilter;
use OwlCorp\HawkAuditor\Filter\EntityTypeFilter;
use OwlCorp\HawkAuditor\Type\OperationType;

final class MatchTypeFilter implements EntityTypeFilter, CacheableFilter
{
    /** @param array<class-string, true> $typesIndex */
    private function __construct(private array $typesIndex, private bool $onMatch, private ?bool $onNonMatch)
    {
    }

    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter -- interface declaration conformity
    public function isTypeAuditable(OperationType $opType, string $entityClass): ?bool
    {
        return isset($this->typesIndex[$entityClass]) ? $this->onMatch : $this->onNonMatch;
    }

    /**
     * Creates a filter that makes the final decision to include all matching types and excluding all non-matching ones
     *
     * This filter has an effect of including only entities listed in the index to be audited, while excluding all other
     * ones which are not listed here. Any other type filters with lower priority will NOT be called and the answer
     * will be cached.
     * This is the implementation for "only_include_types" configuration.
     *
     * @param array<class-string, true> $index Fast-match index of class-strings which should match
     */
    public static function includeOnMatchExcludeOtherwise(array $index): self
    {
        return new self(typesIndex: $index, onMatch: true, onNonMatch: false);
    }

    /**
     * Creates a filter that makes the final decision to exclude all matching types and including all non-matching ones
     *
     * This filter has an effect of excluding only entities listed in the index from being audited, while including all
     * other ones which are not listed here. Any other type filters with lower priority will NOT be called and the
     * answer will be cached.
     * This is the implementation for "only_exclude_types" configuration.
     *
     * @param array<class-string, true> $index Fast-match index of class-strings which should match
     */
    public static function excludeOnMatchIncludeOtherwise(array $index): self
    {
        return new self(typesIndex: $index, onMatch: false, onNonMatch: true);
    }

    /**
     * Creates a filter that makes a decision to include matching types, while ignoring all other ones
     *
     * This filter has an effect of including all entities listed in the index to be audited, while casting an "abstain"
     * vote for types not present on the list. This causes the decision to be passed to a next filter in chain.
     * This is the implementation for "include_types" configuration.
     *
     * @param array<class-string, true> $index Fast-match index of class-strings which should match
     */
    public static function includeOnMatchAbstainOtherwise(array $index): self
    {
        return new self(typesIndex: $index, onMatch: true, onNonMatch: null);
    }

    /**
     * Creates a filter that makes a decision to exclude matching types, while ignoring all other ones
     *
     * This filter has an effect of excluding all entities listed in the index from being audited, while casting an
     * "abstain" vote for types not present on the list. This causes the decision to be passed to a next filter in
     * chain.
     * This is the implementation for "exclude_types" configuration.
     *
     * @param array<class-string, true> $index Fast-match index of class-strings which should match
     */
    public static function excludeOnMatchAbstainOtherwise(array $index): self
    {
        return new self(typesIndex: $index, onMatch: false, onNonMatch: null);
    }

    /**
     * Converts a typical format of a list with classes to a fast lookup index
     *
     * This method is meant to be used during compilation of the app (e.g. in DIC) with the result being precached. This
     * is why it is not integrated into factory methods (i.e. we want to do this conversion once and not on runtime).
     *
     * @param list<class-string> $list
     *
     * @return array<class-string, true>
     */
    public static function createLookupIndex(array $list): array
    {
        return \array_combine($list, \array_fill(0, \count($list), true)); //This is actually 2x faster than foreach
    }
}
