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

/**
 * @phpstan-type TSimpleState array<string, scalar|null>
 * @phpstan-type TState TSimpleState|array<string, mixed>|null
 * Property the TState type should be recursive but: https://github.com/phpstan/phpstan/issues/3006
 */
#[ORM\Embeddable]
final class Change
{
    #[ORM\Column(type: DateTimeImmutableMicroType::NAME)]
    public \DateTimeImmutable $timestamp;

    /**
     * @var TState State of the entity before the operation took place. It can be null for newly created entities.
     */
    #[ORM\Column(nullable: true, type: 'json')]
    public ?array $oldState;

    /**
     * @var TState of the entity before the operation took place. It can be null for newly created entities.
     */
    #[ORM\Column(nullable: true, type: 'json')]
    public ?array $newState;

    /**
     * @deprecated this shouldn't be here - it's a temporary hack for EasyAdmin Bundle list
     */
    public function getOldStateJson(): string
    {
        return \json_encode($this->oldState, \JSON_PRETTY_PRINT|\JSON_THROW_ON_ERROR|\JSON_UNESCAPED_SLASHES);
    }

    /**
     * @deprecated this shouldn't be here - it's a temporary hack for EasyAdmin Bundle list
     */
    public function getNewStateJson(): string
    {
        return \json_encode($this->newState, \JSON_PRETTY_PRINT|\JSON_THROW_ON_ERROR|\JSON_UNESCAPED_SLASHES);
    }
}
