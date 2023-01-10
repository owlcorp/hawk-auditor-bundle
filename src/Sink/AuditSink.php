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

/**
 * AuditSink implementations are tasked with storing the audit changesets passed
 *
 * Audit sink is the final step in the audit changeset journey. At this point the Changeset is sealed (i.e. it must NOT
 * be modified). AuditSink is called as the last step before the database transaction is committed, so that atomicity
 * can be ensured if desired. Naturally, this doesn't matter if a given AuditSink doesn't use the same database as the
 * data or audit isn't in the database at all.
 *
 * Currently, there's no provisioning for confirming that the data was in fact written nor there's a way to stop the
 * audit from happening if the transaction failed (unless the audit uses the same database & transaction as the data).
 * In the future we may introduce additional method, extending AuditSink, which will trigger when the data is confirmed
 * to be persisted to the storage. This way it will be possible to ensure confirmation of persistence of the data with
 * audit logs being sent to e.g. a queue. This will allow for implementations where audit events are sent via e.g.
 * Symfony Messenger to an asynchronous queue and await final flush triggered by the new event post-flush event (and
 * expire if the flush didn't happen).
 * However, you shouldn't worry about all this if you're simply saving events in the same database as the consistency is
 * being guarded by the database itself. Distributed systems are hard thou ;)
 */
interface AuditSink
{
    public function commitAudit(Changeset $changeset): void;
}
