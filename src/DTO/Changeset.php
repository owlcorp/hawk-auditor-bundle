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

namespace OwlCorp\HawkAuditor\DTO;

use OwlCorp\HawkAuditor\DTO\Trigger\Trigger;
use OwlCorp\HawkAuditor\Exception\InvalidArgumentException;
use OwlCorp\HawkAuditor\Type\OperationType;
use Ramsey\Uuid\Uuid;

/**
 * @phpstan-import-type TEntity from EntityRecord
 */
final class Changeset
{
    /**
     * @var string This id is stable and will be saved as-is with the audit record
     */
    public readonly string $id;

    /**
     * @var \DateTimeImmutable When the changeset was closed/sealed. This value isn't available until no more entities
     *                         changes are possible.
     */
    public \DateTimeImmutable $timestamp;

    /**
     * @var Trigger Overarching subsystem which triggered this audit changeset to be created
     */
    public Trigger $trigger;

    /**
     * @var array<string, array<int, EntityRecord>> Main string key is "OperationType->value()"
     */
    private array $entities = [];

    public ?User $author = null;
    public ?User $impersonator = null;

    public function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
    }

    /**
     * @param TEntity $entity
     */
    public function getEntity(OperationType $opType, object $entity): ?EntityRecord
    {
        return $this->entities[$opType->value][\spl_object_id($entity)] ?? null;
    }

    /**
     * @return iterable<int, EntityRecord> WARNING: keys are NOT unique! This is NOT a list!
     */
    public function getAllEntities(): iterable
    {
        foreach ($this->entities as $entityGroup) {
            yield from $entityGroup;
        }
    }

    public function hasChanges(): bool
    {
        return \count($this->entities) > 0;
    }

    /** @internal */
    public function addEntity(OperationType $opType, EntityRecord $dto): void
    {
        $oid = \spl_object_id($dto->entity);

        //This normally cannot happen, as constructing new EntityChange adds it to the changeset. If this failed the
        // EntityChange is broken!
        \assert(!isset($this->entities[$opType->value][$oid]));
        $this->entities[$opType->value][$oid] = $dto;
    }

    public function removeEntity(EntityRecord $entityRecord): void
    {
        $oid = \spl_object_id($entityRecord->entity);
        if (!isset($this->entities[$entityRecord->type->value][$oid])) {
            throw new InvalidArgumentException(
                \sprintf(
                    'Entity record "%s" (type=\"%s\", oid=%d) does not exist in the changeset id=\"%s\"',
                    $entityRecord->id,
                    $entityRecord->type->value,
                    $oid,
                    $this->id
                )
            );
        }

        unset($this->entities[$entityRecord->type->value][$oid]);
    }
}
