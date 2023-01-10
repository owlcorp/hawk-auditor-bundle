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

use OwlCorp\HawkAuditor\DependencyInjection\FilterAwareProcessor;
use OwlCorp\HawkAuditor\DependencyInjection\HawkAuditorExtension;
use OwlCorp\HawkAuditor\Exception\RuntimeException;
use OwlCorp\HawkAuditor\Filter\FilterProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Finds sinks based on tags for a given pipeline and wires them in
 */
class ConfigureFilterProviderPass extends AbstractUoWPass
{
    protected function processUoW(ContainerBuilder $container, string $uowId, Definition $uow, string $pipeline): void
    {
        //First let's check if we even need to deal with filters - the processor may ignore them as a whole
        $procSvcId = \sprintf(HawkAuditorExtension::PROCESSOR_SVC_ID, $pipeline);
        $fpSvcId = \sprintf(HawkAuditorExtension::FILTERS_PROVIDER_SVC_ID, $pipeline);
        $procDef = $container->findDefinition($procSvcId);
        if (!$this->checkProcessorSupportsFilters($container, $pipeline, $procSvcId, $procDef)) {
            $container->log(
                $this,
                \sprintf(
                    'Processor "%s" ("%s") for "%s" pipeline does not support filters - skipping filter configuration',
                    $procSvcId,
                    $procDef->getClass(),
                    $pipeline
                )
            );
            $container->removeDefinition($fpSvcId);
            return;
        }

        $this->finalizeFilterBagConfig($container, $pipeline, $fpSvcId);
        $procDef->setArgument('$filterProvider', new Reference($fpSvcId));
        $defaultConfigArgs = $container->getParameter(\sprintf(HawkAuditorExtension::FILTERS_DEFAULT_PARAM, $pipeline));
        foreach ($defaultConfigArgs as $argName => $argVal) {
            $procDef->setArgument($argName, $argVal);
        }
    }

    private function checkProcessorSupportsFilters(
        ContainerBuilder $container,
        string $pipeline,
        string $procSvcId,
        Definition $procDef
    ): bool {
        $procClass = $procDef->getClass();
        if ($procClass === null) {
            throw new RuntimeException(
                \sprintf(
                    'Service configured as a processor for "%s" pipeline in "%s" has no class defined',
                    $pipeline,
                    HawkAuditorExtension::BUNDLE_ALIAS
                )
            );
        }

        if (!is_subclass_of($procClass, FilterAwareProcessor::class)) {
            $container->log(
                $this,
                \sprintf(
                    'Skipping all filters for "%s" pipeline - the processor service "%s" (class: "%s") is not a ' .
                    'subclass of "%s"',
                    $pipeline,
                    $procSvcId,
                    $procClass,
                    FilterAwareProcessor::class
                )
            );

            return false;
        }

        return true;

    }

    private function finalizeFilterBagConfig(ContainerBuilder $container, string $pipeline, string $fpSvcId)
    {
        $changesetFilterTag = sprintf(HawkAuditorExtension::FILTER_CHANGESET_TAG, $pipeline);
        $hasFieldNamesFilters = \count($container->findTaggedServiceIds($changesetFilterTag)) > 0;

        $fieldProviderSvc = $container->findDefinition($fpSvcId);
        $fieldProviderSvc->setArgument('$hasFieldNameFilters', $hasFieldNamesFilters);
    }
}
