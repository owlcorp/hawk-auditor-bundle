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

use OwlCorp\HawkAuditor\Type\OperationType;

/**
 *
 * @todo this should be abstract and there should be classes for particilar types of operations, so we don't need to do
 *       ->type checks everywhere
 *
 * @phpstan-type TEntity object
 * @phpstan-type TEntityFqcn class-string<TEntity>
 * @phpstan-type TEntityProp string
 * @phpstan-type TStateChange array<TEntityProp, array{mixed|null, mixed|null}>
 */
final class EntityRecord
{
    public const OLD_STATE = 0;
    public const NEW_STATE = 1;

    /**
     * @var Changeset Contains common data for all entities changed within a given transaction. Keep in mind this object
     *                is shared across all EntityChange instances within a flush. Thus, do not put any entity-specific
     *                information there. Use $opaqueData if you want to attach something to the entity itself.
     */
    public readonly Changeset $changeset;

    /**
     * @var string|null Unique identifier of the entity (if known, as for e.g. auto-increment ones it will not be). It
     *                  may be an array if this is a composite identifier.
     */
    public string|array|null $id;

    /**
     * @var class-string<TEntity> FQCN of the entity being changed. While this field may seem redundant, it is NOT for
     *                            some ORMs. When ORMs use proxy objects the real class name is masked with a proxy
     *                            object. In some cases you may see $entityClass === $entity::class, but you should NOT
     *                            rely on this behavior. Use this field instead.
     */
    public readonly string $entityClass;

    /**
     * @var TEntity Entity being changed. You should NOT change any data inside the entity as there are NO guarantees
     *              that the data will be persisted. In fact, you should not do it even if it happens to work in one
     *              point of time, as this is most likely a bug!
     */
    public readonly object $entity;

    /**
     * @var \DateTimeImmutable The last known time when the entity had the $type operation performed. This value can be
     *                         updated as a new value is available. This value is expected to specify a time earlier
     *                         than the changeset time (which normally specifies then the audit log and entities were
     *                         flushed as a txn).
     */
    public \DateTimeImmutable $timestamp;

    /**
     * @var TStateChange 0 => EntityRecord::OLD_STATE, 1 => NEW_STATE
     */
    public array $stateChange;

    /**
     * @var User|null Author of the record (if known). By default, it will hold a reference to Changeset->user. Unless
     *                you're implementing a different user per-record you can leave it as-is in filter. In other words
     *                implementing a filter which changes only the user on Changeset will "change" it on all current and
     *                future records creates in the lifecycle.
     *                Be careful changing this field - you shouldn't modify this object in external processes (e.g.
     *                filters), as it is by default linked via reference to Changeset->author. You can always overwrite
     *                it with a new one thou.
     */
    public ?User $author = null;

    /**
     * @var User|null If user was impersonated during the operation, the impersonated person will be listed in $author
     *                and this field will contain the user who did the impersonation. The field works in the same way
     *                as $author in terms of references.
     *                Be careful changing this field - you shouldn't modify this object in external processes (e.g.
     *                filters), as it is by default linked via reference to Changeset->impersonator. You can always
     *                overwrite it with a new one thou.
     */
    public ?User $impersonator = null;

    /**
     * @var array<mixed>|null Any application-specific data. The audit component doesn't use this field - it's
     *                        intentionally left up to the application.
     */
    public ?array $opaqueData = null;

    /**
     * @var mixed|null Any state which needs to be carried over throughout the audit pipeline. This state IS NOT and
     *                 MUST NOT be persisted
     */
    public mixed $internalState = null;

    /**
     * @param TEntity        $entity
     * @param TEntityFqcn        $entityFqcn
     */
    public function __construct(
        public readonly OperationType $type,
        Changeset $changeset,
        object $entity,
        string $entityFqcn
    ) {
        $this->changeset = $changeset;
        $this->entityClass = $entityFqcn;
        $this->entity = $entity;

        //phpcs:disable SlevomatCodingStandard.PHP.DisallowReference.DisallowedAssigningByReference
        //Attach to changeset by default, it can be unpinned by some filters as needed
        $this->author = &$this->changeset->author;
        $this->impersonator = &$this->changeset->impersonator;
        //phpcs:enable


        $this->updateEntityChangeTimestamp();
        $this->changeset->addEntity($type, $this);
    }

    public function updateEntityChangeTimestamp(): void
    {
        static $utcTz = new \DateTimeZone('UTC');
        $this->timestamp = new \DateTimeImmutable('now', $utcTz);
    }
}
