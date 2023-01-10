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
 * Represents the person/process who caused the audit event to be triggered.
 * Keep in mind that this isn't the complete set of information about the person/process who caused the change. You also
 * need to check the Impersonator, as the Author will "blame" the user who was logged in even if the user is being
 * impersonated.
 */
#[ORM\Embeddable]
final class User
{
    #[ORM\Column(nullable: true)]
    public ?string $class;

    #[ORM\Column(nullable: true)]
    public ?string $uid;

    #[ORM\Column(nullable: true)]
    public ?string $identifier;
}
