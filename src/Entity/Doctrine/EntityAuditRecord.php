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

namespace OwlCorp\HawkAuditor\Entity\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use OwlCorp\HawkAuditor\DTO\Trigger\Trigger;
use OwlCorp\HawkAuditor\Entity\Doctrine\Embedded\Change;
use OwlCorp\HawkAuditor\Entity\Doctrine\Embedded\Changeset;
use OwlCorp\HawkAuditor\Entity\Doctrine\Embedded\Entity;
use OwlCorp\HawkAuditor\Entity\Doctrine\Embedded\ParentEntity;
use OwlCorp\HawkAuditor\Entity\Doctrine\Embedded\User;
use OwlCorp\HawkAuditor\Type\OperationType;
use Ramsey\Uuid\Uuid;

/**
 * Represents a single audited record instance
 *
 * This record is created as soon as the audited entity instance is added to the UoW. Thus, the timestamp here
 * represents the precise moment of creation/update of the record. The flush sequence isn't persisted, as this is
 * an implementation detail of the ORM.
 *
 * @psalm-immutable
 * @phpstan-import-type TTriggerContext from Trigger
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table('_hawk_entity_audit_record')]
class EntityAuditRecord
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    public string $id;

    #[ORM\Column]
    public OperationType $operation;

    /**
     * @var Changeset Information about a single transaction/flush group as persisted to the storage
     */
    #[ORM\Embedded]
    public Changeset $set;

    /**
     * @var ParentEntity For collection items which were disassociated/associated the entity holding the collection will
     *                   not trigger a change, as formally it wasn't changed.
     */
    #[ORM\Embedded]
    public ParentEntity $parent;

    /**
     * @var Entity Information about the entity which was changed in the operation.
     */
    #[ORM\Embedded]
    public Entity $entity;

    /**
     * @var Change Represents set of changes which were made
     */
    #[ORM\Embedded]
    public Change $change;

    /**
     * @var User The user/process who performed the operation. Can be null for unknown ones
     */
    #[ORM\Embedded]
    public User $author;

    /**
     * @var User In situations where Author was being impersonated by another use, this objects contains
     *                        information about the original user who initially impersonated the Author.
     */
    #[ORM\Embedded]
    public User $impersonator;

    /**
     * @var TTriggerContext Serialized information about the action which triggered audit log. Most commonly this will
     *            be a HTTP request.
     */
    #[ORM\Column(type: 'json')]
    public array $action = [];

    /**
     * @var array<mixed>|null Any data set by the user. By default, when no custom events are used, this field will
     *                        always be empty (i.e. this library deliberately doesn't use it for any CRUD events)
     */
    #[ORM\Column(nullable: true, type: 'json')]
    public ?array $opaqueData;

    public function __construct()
    {
        $this->regenerateId();

        $this->set = new Changeset();
        $this->parent = new ParentEntity();
        $this->entity = new Entity();
        $this->change = new Change();
        $this->author = new User();
        $this->impersonator = new User();
    }

    private function regenerateId(): void
    {
        $this->id = Uuid::uuid4()->toString();
    }
    
    public function __clone(): void
    {
        $this->regenerateId();
    }
}
