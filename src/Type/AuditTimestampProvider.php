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

namespace OwlCorp\HawkAuditor\Type;

/**
 * Signifies an entity which wants to provide its own timestamp for audit operations.
 *
 * This is useful when entities are being updates asynchronously from database updates. A prime example
 * is a system which updates entities and sends these updates into a queuing system. In such cases the
 * timestamp of the actual update (which can be correlated between systems) can be, in drastic cases,
 * hours away from the actual database changes.
 */
interface AuditTimestampProvider
{
    public function getAuditTimestamp(): ?\DateTimeInterface;
}
