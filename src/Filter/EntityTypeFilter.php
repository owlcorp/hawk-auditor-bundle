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

use OwlCorp\HawkAuditor\Type\OperationType;

/**
 * Allows you to configure which entity types should (not) be audited
 *
 * Keep in mind results provided by the filter should be deterministic in the scope of a single ORM flush. This is why
 * this class deliberately does not expos entity's object. If you want to implement content-based filtering check
 * EntityFilter instead.
 *
 * In addition, if this interface is combined with CacheableFilter, the results are assumed to be deterministic between
 * ORM flushes (i.e. stored in permanent cache). In most cases you WANT a deterministic filter for performance reasons.
 */
interface EntityTypeFilter
{
    /**
     * @return bool|null True to approve the entity class for being audited, false to deny, and null to abstain. Note:
     *                   In the current implementation, if the filter implements CacheableFilter, only true/false
     *                   answers are cached (nulls are not). However, you should NOT depend on this behavior as it will
     *                   change. ANY votes are assumed cacheable when CacheableFilter is used and this change will NOT
     *                   be considered a BC break.
     */
    public function isTypeAuditable(OperationType $opType, string $entityClass): ?bool;
}
