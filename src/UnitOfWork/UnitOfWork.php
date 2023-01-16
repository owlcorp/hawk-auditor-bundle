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

namespace OwlCorp\HawkAuditor\UnitOfWork;

use OwlCorp\HawkAuditor\DTO\Changeset;

/**
 * Implements Unit of Work pattern for managing a cycle of audit, ensuring transactional changeset processing.
 *
 * The cycle of this UoW is usually tied to ORM's UoW cycle. Events are delivered by AuditProducer(s). Then, once ORM
 * signals the flush, AuditProducer(s) are reponsible for calling flsuh(). This starts the cascade of all EntityRecords
 * (= change events), contained in the Changeset, being delivered via filters/processors/etc to the AuditSink.
 */
interface UnitOfWork
{
    /**
     * Provides the currently active changeset
     *
     * UoW implementation may decide to swap this changeset at any time, i.e. it should not be cached by callers.
     */
    public function getChangeset(): ?Changeset;

    /**
     * Called by AuditProducer when the given changeset should be closed and passed to an AuditSink
     *
     * This method is meant to be executed by an AuditProducer after all entity changes were computed, but before the
     * final transaction was closed. This allows the AuditSink to have a chance to add the audit records to the ORM
     * changeset within the same transaction as the data itself. This ensures atomicity, as some ORMs (e.g. Doctrine)
     * don't have any events for failed transactions, and thus audit may be written when the data wasn't or audit
     * failing after data being already written.
     * Naturally these considerations apply only when the AuditSink is using the same ORM and the same ObjectManager,
     * and not some asynchronous audit delivery (e.g. Symfony Messenger).
     */
    public function flush(): void;

    /**
     * Discards all changes logged by this unit of work
     */
    public function reset(): void;
}
