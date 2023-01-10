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

namespace OwlCorp\HawkAuditor\Entity\Doctrine\Embedded;

use Doctrine\ORM\Mapping as ORM;
use OwlCorp\DoctrineMicrotime\DBAL\Types\DateTimeImmutableMicroType;

#[ORM\Embeddable]
final class Changeset
{
    /**
     * @var string Unique identifier of the transaction. Keep in mind this has nothing to do with DBMS transaction ids!
     */
    #[ORM\Column]
    public string $id;

    /**
     * @var \DateTimeImmutable Precise timestamp of the changeset. All audit records are guaranteed within a changeset
     *      (flush) are guaranteed to have same timestamp here. However, individual records timestamps may differ.
     *      The timestamp will always be in UTC. The changeset timestamp will be as close as possible to the actual
     *      time of the audit record being saved to the storage.
     */
    #[ORM\Column(type: DateTimeImmutableMicroType::NAME)]
    public \DateTimeImmutable $timestamp;
}
