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

/**
 * Represents a state of the entity during the audit operation
 */
#[ORM\Embeddable]
final class ParentEntity
{
    /**
     * @var class-string $class FQCN of the class of the entity
     */
    #[ORM\Column(nullable: true)]
    public ?string $class;

    /**
     * @var string Identifier of the entity
     */
    #[ORM\Column(nullable: true)]
    public ?string $id;
}
