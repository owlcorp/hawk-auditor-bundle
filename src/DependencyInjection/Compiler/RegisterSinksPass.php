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
use OwlCorp\HawkAuditor\Sink\AuditSink;
use OwlCorp\HawkAuditor\Sink\ChainSink;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Finds sinks based on tags for a given pipeline and wires them in
 */
class RegisterSinksPass extends AbstractUoWPass
{
    public const SVC_ID = HawkAuditorExtension::BUNDLE_ALIAS . '.%s.sink.%s'; //vars: pipeline; sink name
    public const TAG_NAME = HawkAuditorExtension::BUNDLE_ALIAS . '.%s.sink'; //vars: pipeline
    public const UOW_PARAM_UNTAGGED_SINKS = HawkAuditorExtension::BUNDLE_ALIAS . '.%s.uow.untagged_sinks';

    private const CHAIN_SINK_SVC_ID = HawkAuditorExtension::BUNDLE_ALIAS . '.%s.chain_sink'; //vars: pipeline

    protected function processUoW(ContainerBuilder $container, string $uowId, Definition $uow, string $pipeline): void
    {
        $sinkTag = \sprintf(self::TAG_NAME, $pipeline);
        $sinks = $container->findTaggedServiceIds($sinkTag);

        //With just one we can simply wire it directly to the UoW
        if (\count($sinks) === 1) {
            $sinkId = \array_key_first($sinks);
            $uow->setArgument(AuditSink::class, new Reference($sinkId));

            $container->log(
                $this,
                \sprintf('Found a single sink ("%s") for "%s" pipeline - attaching directly', $sinkId, $pipeline)
            );

            return;
        }

        //When there are multiple sinks we need to pack them into a chain sink so that they appear as one to UoW
        $sinksRefs = [];
        foreach ($sinks as $sinkId => $_) {
            $sinksRefs[] = new Reference($sinkId);
        }
        $chainSinkId = \sprintf(self::CHAIN_SINK_SVC_ID, $pipeline);
        $chainSink = $container->register($chainSinkId, ChainSink::class);
        $chainSink->setArgument(0, new IteratorArgument($sinksRefs));
        $uow->setArgument(AuditSink::class, new Reference($chainSinkId));
        $container->log(
            $this,
            \sprintf(
                'Found mutliple sinks ("%s") for "%s" pipeline - attaching via "%s" ("%s")',
                \implode('", "', $sinksRefs),
                $pipeline,
                $chainSinkId,
                ChainSink::class
            )
        );
    }
}
