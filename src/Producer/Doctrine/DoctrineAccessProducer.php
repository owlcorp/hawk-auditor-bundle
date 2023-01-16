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

namespace OwlCorp\HawkAuditor\Producer\Doctrine;

use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use OwlCorp\HawkAuditor\DTO\EntityRecord;
use OwlCorp\HawkAuditor\Helper\DoctrineHelper;
use OwlCorp\HawkAuditor\UnitOfWork\HawkUnitOfWork;

/**
 *
 * This is deliberately separated from alters to not even register it when read audit is not enabled (which is
 * most of the systems).
 *
 * @experimental This functionality is NOT finished yet! It should be disabled for now, as it's buggy (needs explicit
 *               flush somewhere as now accesses aren't persisted unless Doctrine flushes).
 */
final class DoctrineAccessProducer implements DoctrineAuditProducer
{
    public function __construct(private HawkUnitOfWork $uow, private DoctrineHelper $dHelper)
    {
    }

    /**
     * @internal called by doctrine
     * @param PostLoadEventArgs|LifecycleEventArgs $evt PostLoadEventArgs is available since Doctrine 2.14
     */
    public function postLoad(object $evt): void
    {
        dd('this should not happen');
        if ($evt instanceof LifecycleEventArgs) {
            $entity = $evt->getObject();
            $om = $evt->getObjectManager();
        } else {
            $entity = $evt->getEntity();
            $om = $evt->getEntityManager();
        }

        $dto = $this->uow->onRead($entity, $this->dHelper->getRealEntityClass($entity::class));
        if ($dto === null) {
            return; //most likely entity excluded by some filters called in UoW
        }

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

    /**
     * @uses postLoad
     * @return array<string>
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::postLoad, //Loaded from database
        ];
    }
}
