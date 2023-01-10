<?php
/**
 * This file is part of OwlCorp/HawkAuditor released under GPLv2.
 *
 * Copyright (c) Gregory Zdanowski-House
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace OwlCorp\HawkAuditor\DependencyInjection\Compiler;

use OwlCorp\DoctrineMicrotime\DBAL\Types\DateTimeImmutableMicroType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RegisterMicrotimeTypePass implements CompilerPassInterface
{
    private const TYPEDEF_BAG = 'doctrine.dbal.connection_factory.types';

    public function process(ContainerBuilder $container)
    {
        if (!$container->hasParameter(self::TYPEDEF_BAG)) {
            $container->log($this, 'Skipping types registration - doctrine/doctrine-bundle not installed');
            return; //probably doctrine-bundle is not installed (i.e. someone is not using Doctrine)
        }

        $typesDef = $container->getParameter(self::TYPEDEF_BAG);
        if (isset($typesDef[DateTimeImmutableMicroType::NAME])) { //user's app is already using the type
            return;
        }

        $container->setParameter(
            self::TYPEDEF_BAG,
            $typesDef + [DateTimeImmutableMicroType::NAME => ['class' => DateTimeImmutableMicroType::class]]
        );
    }
}
