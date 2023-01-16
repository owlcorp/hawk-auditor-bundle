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
use OwlCorp\HawkAuditor\DTO\EntityRecord;
use OwlCorp\HawkAuditor\Factory\ChangesetFactory;
use OwlCorp\HawkAuditor\Processor\AuditProcessor;
use OwlCorp\HawkAuditor\Sink\AuditSink;
use OwlCorp\HawkAuditor\Type\OperationType;

/**
 * Represents a single audit instance, ensuring transactional changeset processing for the duration of clear-flush ORM
 * cycle.
 *
 * Events are delivered by AuditProducer(s). Then, once ORM signals the flush, all EntityRecords (= change events) are
 * processed using a AuditProcessor. The processing engine can further decide to either seal the changeset or discard
 * the changeset. Once sealed, the changeset is delivered to an AuditSink.
 *
 * In the current implementation, only one AuditProcessor and one AuditSink are used. However, there's nothing
 * preventing this from being extended. However, the preferred method to do that would be to introduce something like
 * ChainAuditProcessor and ChainAuditSink *implementations* which internally implement voting logic, similar to Symfony
 * Voters.
 *
 * @phpstan-import-type TEntity from EntityRecord
 * @phpstan-import-type TEntityFqcn from EntityRecord
 */
class HawkUnitOfWork implements AccessUnitOfWork, AlterUnitOfWork
{
    private ?Changeset $changeset = null;
    private bool $changesetFlushing = false;

    public function __construct(
        private ChangesetFactory $changesetFactory,
        private AuditProcessor $auditProcessor,
        private AuditSink $auditSink
    ) {
    }

    public function getChangeset(): ?Changeset
    {
        return $this->changeset;
    }

    public function onCreate(object $entity, string $entityClass): ?EntityRecord
    {
        if ($this->changesetFlushing || !$this->auditProcessor->isTypeAuditable(OperationType::CREATE, $entityClass)) {
            return null;
        }

        return $this->updateChangelog(OperationType::CREATE, $entity, $entityClass);
    }

    public function onRead(object $entity, string $entityClass): ?EntityRecord
    {
        if ($this->changesetFlushing || !$this->auditProcessor->isTypeAuditable(OperationType::READ, $entityClass)) {
            return null;
        }

        return $this->updateChangelog(OperationType::READ, $entity, $entityClass);
    }

    public function onUpdate(object $entity, string $entityClass): ?EntityRecord
    {
        if ($this->changesetFlushing || !$this->auditProcessor->isTypeAuditable(OperationType::UPDATE, $entityClass)) {
            return null;
        }

        return $this->updateChangelog(OperationType::UPDATE, $entity, $entityClass);
    }

    public function onDelete(object $entity, string $entityClass): ?EntityRecord
    {
        if ($this->changesetFlushing || !$this->auditProcessor->isTypeAuditable(OperationType::DELETE, $entityClass)) {
            return null;
        }

        return $this->updateChangelog(OperationType::DELETE, $entity, $entityClass);
    }

    public function flush(): void
    {
        if (!isset($this->changeset)) {
            return;
        }

        $this->changesetFlushing = true; //avoid loops if something (e.g. a sink) triggers audit event

        static $utcTz = new \DateTimeZone('UTC');
        $this->changeset->timestamp = new \DateTimeImmutable('now', $utcTz);
        $sealedSuccessfully = $this->auditProcessor->sealChangeset($this->changeset);
        if ($sealedSuccessfully) {
            $this->auditSink->commitAudit($this->changeset);
        }

        $this->reset();
    }

    public function reset(): void
    {
        $this->changeset = null;
        $this->changesetFlushing = false;
    }

    /**
     * @param TEntity     $entity
     * @param TEntityFqcn $entityFqcn
     */
    private function updateChangelog(OperationType $opType, object $entity, string $entityFqcn): EntityRecord
    {
        if (isset($this->changeset) && ($dto = $this->changeset->getEntity($opType, $entity)) !== null) { // phpcs:ignore
            \assert($opType === $dto->type); //make sure changeset is actually picking the correct entity for type
            $dto->updateEntityChangeTimestamp();
            return $dto;
        }

        $this->changeset ??= $this->changesetFactory->createChangeset();
        return new EntityRecord($opType, $this->changeset, $entity, $entityFqcn);
    }
}
