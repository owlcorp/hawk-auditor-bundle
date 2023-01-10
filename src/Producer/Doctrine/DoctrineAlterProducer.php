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
/**
 * This file is part of OwlCorp/HawkAuditor released under GPLv2.
 *
 * Copyright (c) Gregory Zdanowski-House
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace OwlCorp\HawkAuditor\Producer\Doctrine;

use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork as DoctrineUnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\Event\ManagerEventArgs;
use Doctrine\Persistence\ObjectManager;
use OwlCorp\HawkAuditor\DTO\EntityRecord;
use OwlCorp\HawkAuditor\Exception\ThisShouldNotBePossibleException;
use OwlCorp\HawkAuditor\Helper\DoctrineHelper;
use OwlCorp\HawkAuditor\Type\OperationType;
use OwlCorp\HawkAuditor\UnitOfWork\HawkUnitOfWork;
use OwlCorp\HawkAuditor\UnitOfWork\UnitOfWork;

final class DoctrineAlterProducer implements DoctrineAuditProducer
{
    public function __construct(private UnitOfWork $uow, private DoctrineHelper $dHelper)
    {
    }

    /**
     * @internal called by doctrine
     * @param PrePersistEventArgs|LifecycleEventArgs $evt
     */
    public function prePersist(object $evt): void
    {
        $entity = $evt instanceof LifecycleEventArgs ? $evt->getObject() : $evt->getEntity();
        $this->uow->onCreate($entity, $this->dHelper->getRealEntityClass($entity::class));
    }

    /**
     * @internal called by doctrine
     * @param PreRemoveEventArgs|LifecycleEventArgs $evt
     */
    public function preRemove(object $evt): void
    {
        $entity = $evt instanceof LifecycleEventArgs ? $evt->getObject() : $evt->getEntity();
        $this->uow->onDelete($entity, $this->dHelper->getRealEntityClass($entity::class));
    }

    /** @internal called by doctrine */
    public function onFlush(OnFlushEventArgs $evt): void
    {
        $om = $evt instanceof ManagerEventArgs ? $evt->getObjectManager() : $evt->getEntityManager();
        $dUoW = $om->getUnitOfWork();

        $this->handleInserts($dUoW, $om);
        $this->handleUpdates($dUoW, $om);
        $this->handleDeletes($dUoW, $om);

        //Even thou associations/disassociation happen before main entity is deleted, we should log them last. This is
        // because the association/disassociation events by design aren't logged separately but as an update to the
        // collection-containing entity. After deliberating on this, a decision was made to proceed with such a design
        // as it's much easier to see actual changes to the state of the whole application.
        $this->handleCollectionDeletions($dUoW, $om); //this MUST be before updates, so we can capture clear+add/remove
        $this->handleCollectionUpdates($dUoW, $om);

        $this->uow->flush();
    }

    /** @internal called by doctrine */
    //phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter -- parent declaration conformity
    public function onClear(OnClearEventArgs $evt): void
    {
        $this->uow->reset();
    }

    private function handleInserts(DoctrineUnitOfWork $dUoW, ObjectManager $om): void
    {
        foreach ($dUoW->getScheduledEntityInsertions() as $entity) {
            $dto = $this->uow->getChangeset()->getEntity(OperationType::CREATE, $entity);
            if ($dto === null) {
                continue; //entity was excluded previously (probably by a EntityTypeFilter)
            }

            $dto->internalState = $om;
            $dto->stateChange = $dUoW->getEntityChangeSet($entity);
        }
    }

    private function handleUpdates(DoctrineUnitOfWork $dUoW, ObjectManager $om): void
    {
        foreach ($dUoW->getScheduledEntityUpdates() as $entity) {
            //Updates need special handling. Doctrine does NOT fire onPersist() events for managed entities which were
            // updated, as it simply doesn't know about them. The ->persist() needs to be called only for unmanaged
            // entities. So we probably don't have that entity in our changeset.
            //The API is kept as a standard as other ORMs may have events pertain to individual changes and this library
            // isn't doctrine-specific.

            //There's also another special case here - even if entity wasn't called to be ->persist()'ed and any of its
            // *ToMany collections changed, it will be returned as a part of getScheduledEntityUpdates() BUT with
            // empty getEntityChangeSet() ;D
            $dto = $this->uow->onUpdate($entity, $this->dHelper->getRealEntityClass($entity::class));
            if ($dto === null) {
                continue; //entity was excluded previously (probably by a EntityTypeFilter)
            }

            $dto->internalState = $om;
            $dto->stateChange = $dUoW->getEntityChangeSet($entity);
        }
    }

    private function handleDeletes(DoctrineUnitOfWork $dUoW, ObjectManager $om): void
    {
        foreach ($dUoW->getScheduledEntityDeletions() as $entity) {
            $dto = $this->uow->getChangeset()->getEntity(OperationType::DELETE, $entity);
            if ($dto === null) {
                continue; //entity was excluded previously (probably by a EntityTypeFilter)
            }
            $dto->internalState = $om;

            $meta = $this->dHelper->metaCache[$dto->entityClass] ??= $om->getClassMetadata($dto->entityClass);
            //This will be a problem if Doctrine changes it to a more restrictive ClassMetadata... a lot of manual
            // reflections and essentially rebuilding what they already have. However, they will probably move it to
            // a different place which isn't available yet.
            \assert(
                $meta instanceof ClassMetadataInfo,
                'Expected metadata of ' . ClassMetadataInfo::class . ', got ' . $meta::class
            );

            //Doctrine will not have changesets for deleted entities as it doesn't care about something which is meant
            // to be gone. Moreover, these entities may even be in an unloaded proxy state (i.e. they only have their
            // id populated). This is why to fully audit delete we need to dump its state.
            foreach ($this->dHelper->dumpState($meta, $entity) as $field => $state) {
                $dto->stateChange[$field] = [EntityRecord::OLD_STATE => $state, EntityRecord::NEW_STATE => null];
            }
        }
    }

    //phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- performance-sensitive code
    private function handleCollectionDeletions(DoctrineUnitOfWork $dUoW, ObjectManager $om): void
    {
        //This essentially replicates what \Doctrine\ORM\Persisters\Collection\ManyToManyPersister::delete() does
        foreach ($dUoW->getScheduledCollectionDeletions() as $collection) {
            \assert($collection instanceof PersistentCollection); //no other collection can be deleted
            $owningSide = $collection->getOwner();
            if ($owningSide === null) { //TODO: I actually don't know when it can return null hmm...
                continue;
            }

            $mapping = $collection->getMapping();
            if ($mapping === null || !$mapping['isOwningSide']) {
                //we've got inverse side which according to doctrine docs should be ignored
                // https://www.doctrine-project.org/projects/doctrine-orm/en/2.14/reference/unitofwork-associations.html
                continue;
            }

            //When collection are cleared and nothing else is happening with the entity, the entity isn't scheduled for
            // update (which is slightly weird as it is when collection items are added...)
            if ($dUoW->isScheduledForUpdate($owningSide)) { //the collection is cleared AND other fields are modified
                $ownerDto = $this->uow->getChangeset()->getEntity(OperationType::UPDATE, $owningSide);
            } elseif ($dUoW->isScheduledForDelete($owningSide)) { //the whole entity is going away
                $ownerDto = $this->uow->getChangeset()->getEntity(OperationType::DELETE, $owningSide);
            } else {
                //we need to "fake" an update as doctrine doesn't consider just collection deletes as updates
                //however, it does consider collection updates an entity update with empty changeset... go figure ;)
                $ownerDto = $this->uow->onUpdate(
                    $owningSide,
                    $this->dHelper->getRealEntityClass($owningSide::class)
                );
            }

            if ($ownerDto === null) { //entity was excluded by e.g. type filter
                continue;
            }

            $ownerDto->internalState = $om;
            $ownerDto->stateChange[$mapping['fieldName']] = [
                //we cannot get any diff at this stage. When collection is scheduled for DELETION it will not have
                // any diffs, so the OLD_STATE is truly the whole collection and has [so far] no new state
                //keep in mind this exact state can be set here (old=collection, new=null) or in the handleDeletes for
                // when whole objects are being removed (in such cases doctrine doesn't trigger collection delete?!)
                EntityRecord::OLD_STATE => $collection,
                EntityRecord::NEW_STATE => null, //this may change in handleCollectionUpdates
            ];
        }
    }

    /**
     * This method is used when collection is changed in any way other than ->clear() or complete deletion of the parent
     * In these cases handleCollectionDeletions() is used.
     */
    //phpcs:disable SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- performance-sensitive code
    //phpcs:ignore SlevomatCodingStandard.Functions.FunctionLength -- performance-sensitive code
    private function handleCollectionUpdates(DoctrineUnitOfWork $dUoW, ObjectManager $om): void
    {
        //This essentially replicates what \Doctrine\ORM\Persisters\Collection\ManyToManyPersister::update() does
        foreach ($dUoW->getScheduledCollectionUpdates() as $collection) {
            \assert($collection instanceof PersistentCollection); //no other collection can be updated
            $owningSide = $collection->getOwner();
            if ($owningSide === null) { //TODO: I actually don't know when it can return null hmm...
                continue;
            }

            $mapping = $collection->getMapping();
            if ($mapping === null || !$mapping['isOwningSide']) {
                //we've got inverse side which according to doctrine docs should be ignored
                // https://www.doctrine-project.org/projects/doctrine-orm/en/2.14/reference/unitofwork-associations.html
                continue;
            }

            //Hmm, this potentially can be optimized to $this->>uow->changeset->getEntity() as doctrine always marks
            // the entity as updated even if only the collection has changed. However, I'm not sure if this is a stable
            // behavior? [todo]
            //Also, an entity cannot be in an insert AND update state in the same transaction, so it's safe to assume
            // we can test for update and if not create
            if ($dUoW->isScheduledForUpdate($owningSide)) {
                $potentialDto = $this->uow->getChangeset()->getEntity(OperationType::UPDATE, $owningSide);
                $ownerDto = $this->uow->onUpdate(
                    $owningSide,
                    $this->dHelper->getRealEntityClass($owningSide::class)
                );
            } elseif ($dUoW->isScheduledForInsert($owningSide)) {
                $potentialDto = $this->uow->getChangeset()->getEntity(OperationType::CREATE, $owningSide);
                $ownerDto = $this->uow->onCreate(
                    $owningSide,
                    $this->dHelper->getRealEntityClass($owningSide::class)
                );
            } else {
                throw ThisShouldNotBePossibleException::reportIssue(
                    'Collection scheduled for update without CREATE or UPDATE scheduled - please report this.'
                );
            }
            //see note above the "big if()" above
            if (($potentialDto === null && $ownerDto !== null) || $potentialDto !== $ownerDto) {
                throw ThisShouldNotBePossibleException::reportIssue(
                    'Existing changeset entity is different than updated one - please, report this.'
                );
            }


            if ($ownerDto === null) { //entity was excluded by e.g. type filter
                continue;
            }
            $ownerDto->internalState = $om;

            //We DO NOT calculate any diffs here for many reasons (performance being one, but also complexity of
            // collections). See DoctrineStateMarshaller::describeToManyRelation for details.
            //In short, we will get delete diffs from OLD_STATE and insert diffs from NEW_STATE. If the collection in
            // old is scheduled for deletion by UoW, we need to get full data and not diffs.
            if (isset($ownerDto->stateChange[$mapping['fieldName']])) { //it's probably scheduled for deletion
                $ownerDto->stateChange[$mapping['fieldName']][EntityRecord::NEW_STATE] = $collection;
            } else {
                $ownerDto->stateChange[$mapping['fieldName']] = [
                    EntityRecord::OLD_STATE => $collection, //it may or may not have old state in a form of dirty/diffs
                    EntityRecord::NEW_STATE => $collection,
                ];
            }
        }
    }
    //phpcs:enable

    /**
     * @uses prePersist
     * @uses preRemove
     * @uses onFlush
     * @uses onClear
     * @return array<string>
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist, //Create (does NOT trigger for updates)
            Events::preRemove, //Delete
            Events::onFlush, //Compute changes & persist to database
            Events::onClear, //Doctrine UoW reset
        ];
    }
}
