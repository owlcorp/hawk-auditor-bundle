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

namespace OwlCorp\HawkAuditor\Filter\Changeset;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork as DoctrineUoW;
use OwlCorp\HawkAuditor\DTO\Changeset;
use OwlCorp\HawkAuditor\DTO\EntityRecord;
use OwlCorp\HawkAuditor\DTO\EntityRecord as ERecord;
use OwlCorp\HawkAuditor\Filter\ChangesetFilter;
use OwlCorp\HawkAuditor\Helper\DoctrineHelper;

/**
 * Normalized entities oldState/newState for storage as audit events
 *
 * Internally, Doctrine doesn't have precomputed "flat" state of its entities after change tracking is complete. This
 * is because changes tracking happens on the level of UoW, while the conversion between PHP and DB types happens in a
 * persistence layer. The flow in Doctrine, goes in general as follows:
 *  ORM UoW flush => ORM BasicEntityPersister::executeInserts => DBAL Statement::bindValue
 * The actual conversion from PHP types, defined in ORM => DBAL mapping, happens in DBAL layer. Thus, the state of
 * entities data, as computed for the DB cannot be captured. This is why this normalizer exists.
 *
 * This normalizer isn't part of the Doctrine producer. There are two reasons for that:
 *  1) by design, all filters should receive data as close to the application data as possible
 *  2) converting values on the producer level would mean that the conversion is performed for no reason for fields
 *     which may be filtered out later
 *
 * In addition, which may be non-obvious at first, ensures the internal Doctrine state doesn't leak (e.g. properties
 * like__initializer__ are present in the state) into the audit.
 *
 * @phpstan-import-type TEntity from EntityRecord
 * @phpstan-import-type TEntityFqcn from EntityRecord
 * @phpstan-import-type TSerializedEntityId from DoctrineHelper
 */
final class DoctrineStateMarshaller implements ChangesetFilter
{
    public function __construct(private DoctrineHelper $dHelper)
    {
    }

    //phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- it is VERY verbose & commented
    public function onAudit(Changeset $changeset): bool
    {
        $lastOM = null;
        foreach ($changeset->getAllEntities() as $record) {
            //OM/EM is carried from the producer, which got it from Doctrine in the event args. Since multiple producers
            // may gather events from multiple EMs, we cannot simply just take a random EM from DIC here!
            $om = $record->internalState;
            \assert($om instanceof EntityManagerInterface);

            if ($lastOM !== $om) { //optimization via profiler to not reload the same deep deps
                $platform = $om->getConnection()->getDatabasePlatform();
                $dUoW = $om->getUnitOfWork();
                $lastOM = $om;
            }

            $metadata = $this->dHelper->metaCache[$record->entityClass] ??= $om->getClassMetadata($record->entityClass);
            $record->id = $this->dHelper->getScalarEntityIds($om, $platform, $record->entity, $metadata);
            if (\count($record->id) === 1) { //if this is a simple ID we can just squash it to a scalar value
                //array_values is surprisingly the fastest, only beaten by current() but we cannot ensure pointer here
                //tested solutions: array_values()[0], reset(), current(), array_key_first(), array_reverse+pop
                $singleId = \array_values($record->id)[0];
                if (\is_scalar($singleId)) {
                    $record->id = (string)$singleId;
                }
            }

            $this->marshallStateChange($platform, $dUoW, $om, $record, $metadata);
        }

        return true;
    }

    //phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- it is VERY verbose & commented
    private function marshallStateChange(
        AbstractPlatform $platform,
        DoctrineUoW $dUoW,
        EntityManagerInterface $om,
        EntityRecord $record,
        ClassMetadataInfo $metadata
    ): void {
        foreach ($record->stateChange as $prop => [ERecord::OLD_STATE => $old, ERecord::NEW_STATE => $new]) {
            //Case (1) VO embedded in this prop (i.e. no native type). This means that the prop will have multiple
            // sub-fields listed. E.g. for "x" prop with vo containing "a" and "b" you will see: x, x.a, and x.b.
            // If the prop itself is embedded we just skip it as we will deal with it later when subfields are
            // presented.
            if (isset($metadata->embeddedClasses[$prop])) {
                unset($record->stateChange[$prop]); //side note: unset in foreach is faster than building new array
                continue;
            }

            //Fields in doctrine are any properties which are not associations, so this is one
            if ($metadata->hasField($prop)) {
                $record->stateChange[$prop] = [
                    ERecord::OLD_STATE => $this->dHelper->convertToDatabaseValue($platform, $metadata, $prop, $old),
                    ERecord::NEW_STATE => $this->dHelper->convertToDatabaseValue($platform, $metadata, $prop, $new),
                ];
                continue;
            }

            //It's a collection/*ToMany association
            if ($metadata->isCollectionValuedAssociation($prop)) {
                $record->stateChange[$prop] =
                    $this->serializeToManyChanges($platform, $dUoW, $om, $record->entity, $metadata, $prop, $old, $new);
                continue;
            }

            //It's a *ToOne association
            if ($metadata->isSingleValuedAssociation($prop)) {
                $record->stateChange[$prop] = [
                    ERecord::OLD_STATE => $old === null ? null
                        : $this->dHelper->serializeEntityIdentity($om, $platform, $old, $metadata),
                    ERecord::NEW_STATE => $new === null ? null
                        : $this->dHelper->serializeEntityIdentity($om, $platform, $new, $metadata),
                ];

                continue;
            }

            //This means it wasn't a field, nor *ToOne, nor *ToMany... so it should be a doctrine internal property
            // like __initialized__, but it is still a relation?!
            \assert(!$metadata->hasAssociation($prop));
        }
    }

    /**
     * Describes a collection (*toMany relationship) with minimal resources usage
     *
     * Pro-tip before you start: To understand things below, you should probably read the text below first, then read
     * \Doctrine\ORM\UnitOfWork::computeChangeSet() and then re-read this text alongside the open code.
     *
     *
     * This code is very close to Doctrine's internals. Collections are actually a very hard topic, as they can be in
     * many states and of many types. The basic three types:
     *  - eager: has all entities loaded when parent object is loaded and can accept any operations w/o DB access
     *  - lazy: is either initialized or not; when ANY operation is done on it, it will trigger initialization of the
     *          whole collection from the database
     *  - extra lazy: is either initialized or not; SOME operations will trigger loading of the collection but some will
     *                only load objects in question or even custom queries (e.g. ->count()) without any children being
     *                loaded
     *
     * It seems simple at first, until you realize these three types apply to already managed collections. In Doctrine
     * this means descendents of AbstractLazyCollection (e.g. PersistentCollection). When new collection is present in
     * an entity it will be most likely an ArrayCollection which is a glorified array and has all the objects present.
     * In ArrayCollection adding and removing objects is simple: you add or remove something, and it will just be there,
     * as Doctrine needs to INSERT every element anyway. To simplify handling of new vs existing collections, any flat
     * arrays of objects are converted to ArrayCollection, and such ArrayCollections are wrapped into
     * PersistentCollection in UnitOfWork::computeChangeSet().
     *
     * The things get complicated when a parent and its collection already exist. Doctrine will NOT rewrite the
     * collection every time, as this would be a nightmare with e.g. 1M objects. Instead, when a new item is added or
     * removed from PersistentCollection, the collection is marked as "dirty" and these changes are stored alongside. To
     * the user, the collection works as normal: removed items will not show up, added items will be there, counts will
     * factor in these changes etc. When it's time to flush doctrine will use ->getInsertDiff() and ->getDeleteDiff() to
     * only save real changes. This is how we can get what has changes in these collections. This works perfectly for
     * parents which are being UPDATEd. From Doctrine's perspective the $parent object will be marked as updated but
     * with no fields changed (as for Doctrine a *ToMany relation is NOT a field!).
     *
     * Things get even more complicated when dealing with objects which are deleted OR collections which are cleared
     * using $parent->collection->clear(). In such case Doctrine will NOT mark the collection as updated and there will
     * be nothing in ->getDeleteDiff(). This is because a separate bulk-delete query will be issued to remove all
     * elements from a collection. The collection itself doesn't actually remember its ->clear(). Only UoW does. This is
     * why when dealing with collection we MUST check UoW->isCollectionScheduledForDeletion() first and potentially
     * get the collection state from the database to get the state before clear-flush takes place. However, the fact
     * that collection was scheduled for deletion does NOT mean the collection doesn't have new data in it :D It is
     * perfectly valid for a collection field to be ->isCollectionScheduledForDeletion() === true AND also return some
     * records in ->getInsertDiff(), as the user could do ->collection->clear() and then ->collection->add().
     * This works SLIGHTLY differently when the whole parent object is being deleted and it has ManyToMany relationship
     * (implicit middle table orphan removal). In such case the collection isn't marked as scheduled for deletion, but
     * the object will be marked as changing from collection to null in the main deletion diff.
     *
     * One situation which (I think, I've never seen it) isn't possible is having scheduled deletion AND delete diff,
     * as when collection is cleared (gets scheduled for deletion) it will start behaving like ArrayCollection, i.e. add
     * and remove items internally and only INSERT the end state
     *
     *
     * Oh, there's one more thing about collections:
     * In addition to that, objects in collections are NOT guaranteed to be loaded. They can be in a proxy state and
     * only have their primary keys available. Touching such objects may trigger individual queries for every object to
     * retrieve non-PK fields.
     *
     * TODO: this needs some "with label" mode for those who use auto_increment keys
     *
     *
     *
     * @param PersistentCollection<array-key, mixed> $old       for deletes/clears $old is the old collection and needs
     *                                                          load; for updates it's needs a diff so both old and new
     *                                                          are needed (even thou they should be the same but
     *                                                          semantics)... so if $old is null then
     *                                                          handleCollectionUpdates is broken
     * @param PersistentCollection<array-key, mixed>|null $new  this is expected to be called after doctrine already
     *                                                          computed changes and converted all arrays into
     *                                                          ArrayCollection and wrapped them into
     *                                                          PersistentCollection
     *
     *
     * @return array{
     *               array{
     *                     "#fqcn": TEntityFqcn,
     *                     "#-"?: list<TSerializedEntityId>,
     *                     "#+"?: list<TSerializedEntityId>
     *                    }|null,
     *               array{
     *                     "#fqcn": TEntityFqcn,
     *                     "#-"?: list<TSerializedEntityId>,
     *                     "#+"?: list<TSerializedEntityId>
     *                    }|null
     *              }
     */
    private function serializeToManyChanges(
        AbstractPlatform $platform,
        DoctrineUoW $dUoW,
        EntityManagerInterface $om,
        object $owner,
        ClassMetadataInfo $ownerMeta,
        string $ownerProp,
        PersistentCollection $old,
        ?PersistentCollection $new
    ): array {
        $elementFqcn = $ownerMeta->getAssociationTargetClass($ownerProp);
        $elementMeta = $this->dHelper->metaCache[$elementFqcn] ??= $om->getClassMetadata($elementFqcn);

        //collection is being nuked OR the whole object is removed
        if ($dUoW->isCollectionScheduledForDeletion($old) || $new === null) {
            \assert(\count($old->getDeleteDiff()) === 0); //this implies the collection is deleted but has diff?!

            $oldState = [
                '#fqcn' => $elementFqcn,
                '#-' => $this->dHelper->getScalarCollectionEntitiesIds(
                    $om,
                    $platform,
                    $owner,
                    $ownerMeta,
                    $ownerProp,
                    $old
                ),
            ];
        } else { //collection is being updated
            $delDiff = $old->getDeleteDiff();
            $oldState = isset($delDiff[0]) ? [
                '#fqcn' => $elementFqcn,
                '#-' => $this->dHelper->getScalarEntitiesIds($om, $platform, $delDiff, $elementMeta),
            ] : null;
        }

        //Collection will be deleted/cleared and nothing will be added in its place (either parent is going away or
        // someone did ->collection->clear() or something similar)
        if ($new === null) {
            \assert(\count($old->getInsertDiff()) === 0); //this doesn't make sense if there is no $new
            $newState = null;
        } else {
            $newState = [
                '#fqcn' => $elementFqcn,
                '#+' => $this->dHelper->getScalarEntitiesIds($om, $platform, $new->getInsertDiff(), $elementMeta),
            ];
        }


        return [
            ERecord::OLD_STATE => $oldState,
            ERecord::NEW_STATE => $newState,
        ];
    }
}
