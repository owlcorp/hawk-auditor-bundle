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

namespace OwlCorp\HawkAuditor\DependencyInjection;

use OwlCorp\HawkAuditor\Filter\FilterProvider;

/**
 * Optional interface for processors wishing to receive configuration for filers
 *
 * This interface contains a signature for a constructor, so that DIC can wire arguments correctly. Arguments will be
 * matched by their name (e.g. "$filterProvider"), so you can reorder arguments as you wish.
 */
interface FilterAwareProcessor
{
    public function __construct(FilterProvider $filterProvider, bool $defaultAuditType, bool $defaultAuditField);
}
