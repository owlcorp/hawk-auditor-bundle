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

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use OwlCorp\HawkAuditor\DTO\Changeset;
use OwlCorp\HawkAuditor\DTO\EntityRecord;
use OwlCorp\HawkAuditor\DTO\User as DTOUser;
use OwlCorp\HawkAuditor\Entity\Doctrine\Embedded\User;
use OwlCorp\HawkAuditor\Entity\Doctrine\EntityAuditRecord;
use OwlCorp\HawkAuditor\Type\OperationType;

/**
 * Persists audit events from the changeset into a Doctrine ObjectManager
 *
 * Currently, this sink only supports the default ObjectManager instance (uses primary one). In the future it can be
 * easily extended to support external database. However, there's a big gotcha with that: it breaks atomicity. Read
 * the AuditSink interface description VERY carefully.
 *
 * @phpstan-import-type TStateChange from EntityRecord
 */
final class DoctrineOrmSink implements AuditSink
{
    private const ID_JSON_OPTS = \JSON_UNESCAPED_LINE_TERMINATORS | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE |
                                 \JSON_THROW_ON_ERROR;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function commitAudit(Changeset $changeset): void
    {
        $uow = $this->em->getUnitOfWork();
        $earMeta = $this->em->getClassMetadata(EntityAuditRecord::class);

        foreach ($this->transformToEntity($changeset) as $entity) {
            $this->em->persist($entity);
            $uow->computeChangeSet($earMeta, $entity);
        }
    }

    /** @return iterable<EntityAuditRecord> */
    private function transformToEntity(Changeset $changesetDto): iterable
    {
        $entityTpl = new EntityAuditRecord();
        $entityTpl->set->id = $changesetDto->id;
        $entityTpl->set->timestamp = $changesetDto->timestamp;

        $entityTpl->author = $this->createOrmUser($changesetDto->author);
        $entityTpl->impersonator = $this->createOrmUser($changesetDto->impersonator);

        $entityTpl->action = ['source' => $changesetDto->trigger->getSource()->value]
                             + $changesetDto->trigger->getContext();

        foreach ($changesetDto->getAllEntities() as $auditRecord) {
            $entity = clone $entityTpl;
            $entity->operation = $auditRecord->type;

            //In 99% of the cases all entities will have the same author & impersonator, which are set on template
            if ($auditRecord->author !== $changesetDto->author) {
                $entity->author = $this->createOrmUser($auditRecord->author);
            }
            if ($auditRecord->impersonator !== $changesetDto->impersonator) {
                $entity->impersonator = $this->createOrmUser($auditRecord->impersonator);
            }

            $entity->entity->class = $auditRecord->entityClass;
            $entity->entity->id = \is_array($auditRecord->id)
                ? \json_encode($auditRecord->id, self::ID_JSON_OPTS) : (string)$auditRecord->id;
            $this->splitState($auditRecord->type, $auditRecord->stateChange, $entity);
            $entity->change->timestamp = $auditRecord->timestamp;
            $entity->opaqueData = $auditRecord->opaqueData;

            yield $entity;
        }
    }

    /**
     * @param TStateChange $stateChange
     */
    private function splitState(OperationType $opType, array $stateChange, EntityAuditRecord $entity): void
    {
        switch ($opType) {
            //has both old and new states
            case OperationType::UPDATE:
                $entity->change->oldState = $entity->change->newState = [];
                foreach ($stateChange as $field => $fieldDiff) {
                    $entity->change->oldState[$field] = $fieldDiff[EntityRecord::OLD_STATE];
                    $entity->change->newState[$field] = $fieldDiff[EntityRecord::NEW_STATE];
                }
                break;

            //has only new state
            case OperationType::CREATE:
                $entity->change->oldState = null;
                $entity->change->newState = [];
                foreach ($stateChange as $field => $fieldDiff) {
                    $entity->change->newState[$field] = $fieldDiff[EntityRecord::NEW_STATE];
                }
                break;

            //has only old state
            case OperationType::READ:
            case OperationType::DELETE:
            case OperationType::SNAPSHOT:
                $entity->change->oldState = [];
                $entity->change->newState = null;
                foreach ($stateChange as $field => $fieldDiff) {
                    $entity->change->oldState[$field] = $fieldDiff[EntityRecord::OLD_STATE];
                }
                break;
        }
    }

    private function createOrmUser(?DTOUser $dtoUser): User
    {
        $ormUser = new User();

        if ($dtoUser !== null) {
            $ormUser->class = $dtoUser->class;
            $ormUser->uid = $dtoUser->id;
            $ormUser->identifier = $dtoUser->name;
        }

        return $ormUser;
    }
}
