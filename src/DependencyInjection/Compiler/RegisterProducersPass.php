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

namespace OwlCorp\HawkAuditor\DependencyInjection\Compiler;

use OwlCorp\HawkAuditor\DependencyInjection\HawkAuditorExtension;
use OwlCorp\HawkAuditor\DependencyInjection\InjectionHelper;
use OwlCorp\HawkAuditor\UnitOfWork\UnitOfWork;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

//this needs to run BEFORE custom doctrine event reg! => but why... it was late ;<
class RegisterProducersPass extends AbstractUoWPass
{
    public const SVC_ID = HawkAuditorExtension::BUNDLE_ALIAS . '.%s.producer.%s'; //vars: pipeline; producer
    public const TAG_NAME = HawkAuditorExtension::BUNDLE_ALIAS . '.%s.producer'; //vars: pipeline
    public const UOW_PARAM_UNTAGGED_PRODUCERS = HawkAuditorExtension::BUNDLE_ALIAS . '.%s.uow.untagged_producers';

    public function __construct(private InjectionHelper $injHelper)
    {
    }

    protected function processUoW(ContainerBuilder $container, string $uowId, Definition $uow, string $pipeline): void
    {
        $producerTag = \sprintf(self::TAG_NAME, $pipeline);
        $this->tagCustomServices($container, $pipeline, self::UOW_PARAM_UNTAGGED_PRODUCERS, $producerTag);

        foreach ($container->findTaggedServiceIds($producerTag) as $producerId => $producerTagAttrs) {
            $this->injHelper->injectReferenceIntoDefinition(
                $uowId,
                UnitOfWork::class,
                $pipeline,
                $producerId,
                $container->findDefinition($producerId)
            );

            $container->log(
                $this,
                \sprintf('Injected UoW "%s" into "%s" producer in "%s" pipeline', $uowId, $producerId, $pipeline)
            );
        }
    }
}
