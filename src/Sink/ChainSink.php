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

namespace OwlCorp\HawkAuditor\Sink;

use OwlCorp\HawkAuditor\DTO\Changeset;

final class ChainSink implements AuditSink
{
    /**
     * @param iterable<AuditSink> $auditSinks
     */
    public function __construct(private iterable $auditSinks)
    {
    }

    public function commitAudit(Changeset $changeset): void
    {
        foreach ($this->auditSinks as $sink) {
            $sink->commitAudit($changeset);
        }
    }
}
