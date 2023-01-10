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

use OwlCorp\HawkAuditor\DTO\EntityRecord;

/**
 * {@inheritDoc}
 *
 * @experimental this interface isn't finalized and things may change
 *
 * @phpstan-import-type TEntity from EntityRecord
 * @phpstan-import-type TEntityFqcn from EntityRecord
 */
interface AlterUnitOfWork extends UnitOfWork
{
    /**
     * Called by AuditProducer instances when a new entity is created in the system
     *
     * @param TEntity     $entity      Entity object which was created
     * @param TEntityFqcn $entityClass Real FQCN of the entity passed in $entity; may be a subclass of it
     *
     * @return EntityRecord|null EntityRecord in the audit for a given entity, or null if the audit was early-rejected
     */
    public function onCreate(object $entity, string $entityClass): ?EntityRecord;

    /**
     * Called by AuditProducer instances when an existing entity is updated in the system
     *
     * @param TEntity     $entity      Entity object which was created
     * @param TEntityFqcn $entityClass Real FQCN of the entity passed in $entity; may be a subclass of it
     *
     * @return EntityRecord|null EntityRecord in the audit for a given entity, or null if the audit was early-rejected
     */
    public function onUpdate(object $entity, string $entityClass): ?EntityRecord;

    /**
     * Called by AuditProducer instances when an existing entity is deleted from the system
     *
     * @param TEntity     $entity      Entity object which was created
     * @param TEntityFqcn $entityClass Real FQCN of the entity passed in $entity; may be a subclass of it
     *
     * @return EntityRecord|null EntityRecord in the audit for a given entity, or null if the audit was early-rejected
     */
    public function onDelete(object $entity, string $entityClass): ?EntityRecord;
}
