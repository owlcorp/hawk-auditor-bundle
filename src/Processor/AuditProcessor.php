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

use OwlCorp\HawkAuditor\DTO\Changeset;
use OwlCorp\HawkAuditor\Type\OperationType;

/**
 * Implements the "event processing engine" EDA layer (https://en.wikipedia.org/wiki/Event-driven_architecture). Events
 * generated by AuditProducer are redirected to the AuditProcessor by the UnitOfWork for decision-making & modification.
 * Then, changesets (containing events in a form of EntityRecords) after the being sealed, are dropped into designated
 * ChangesetSink.
 */
interface AuditProcessor
{
    /**
     * Early-fail check for whether a given type should be audited
     *
     * @param class-string  $entityClass
     *
     * @return bool True to include a given class in audit, false to ignore it
     */
    public function isTypeAuditable(OperationType $opType, string $entityClass): bool;

    /**
     * Called just before changeset is closed to make last-minute adjustments and processing before it is sent to sinks
     *
     * This is the last moment to edit the changeset. After sealChangeset() is called and this method completes, the
     * AuditProcessor implementation should assume that passed $changeset is no longer valid.
     *
     *
     * @return bool True to approve the changeset and send it to the sinks; false to abort and discard the changeset
     */
    public function sealChangeset(Changeset $changeset): bool;
}
