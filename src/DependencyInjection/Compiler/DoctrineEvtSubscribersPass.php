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
use OwlCorp\HawkAuditor\Exception\InvalidArgumentException;
use OwlCorp\HawkAuditor\Exception\RuntimeException;
use OwlCorp\HawkAuditor\Exception\ThisShouldNotBePossibleException;
use OwlCorp\HawkAuditor\HawkAuditorBundle;
use Symfony\Bridge\Doctrine\ContainerAwareEventManager;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

class DoctrineEvtSubscribersPass implements CompilerPassInterface
{
    public const TAG_NAME = HawkAuditorExtension::BUNDLE_ALIAS . '.doctrine_event_subscriber';
    public const TAG_EM_ATTR = 'manager';

    public function process(ContainerBuilder $container)
    {
        foreach ($container->findTaggedServiceIds(self::TAG_NAME) as $svcId => $tagAttrs) {
            if (\count($tagAttrs) === 0) {
                throw new InvalidArgumentException(
                    \sprintf(
                        'Expected "%s" service marked with "%s" tag to have at least one "%s" attribute. ' .
                        'No attributes were found with the tag.',
                        $svcId,
                        self::TAG_NAME,
                        self::TAG_EM_ATTR
                    )
                );
            }

            foreach ($tagAttrs as $tagAttr) {
                if (!isset($tagAttr[self::TAG_EM_ATTR])) {
                    throw new InvalidArgumentException(
                        \sprintf(
                            'Expected "%s" service marked with "%s" tag to have at least one "%s" attribute. ' .
                            'The attribute was not set on at least one of the tags.',
                            $svcId,
                            self::TAG_NAME,
                            self::TAG_EM_ATTR
                        )
                    );
                }

                $managerName = $tagAttr[self::TAG_EM_ATTR];
                $this->registerSubscriberAtEvtMgt($container, $svcId, $managerName);
                $container->log($this, \sprintf('Registered "%s" as event subscriber with "%s"', $svcId, $managerName));
            }
        }
    }


    private function registerSubscriberAtEvtMgt(ContainerBuilder $container, string $svcId, string $manager): void
    {
        try {
            $emDef = $this->resolveEvtMgrDefinition($container, $svcId, $manager);
            $emDefClass = $container->getParameterBag()->resolveValue($emDef->getClass());
        } catch (\Throwable $t) {
            throw new ThisShouldNotBePossibleException(
                \sprintf(
                    'Cannot register "%s" service marked with "%s" tag as an event subscriber at "%s" entity ' .
                    'manager. Resolving doctrine bridge class failed - please report this as a bug. %s',
                    $svcId,
                    self::TAG_NAME,
                    $manager,
                    HawkAuditorBundle::getBugReportStatement()
                ),
                $t->getCode(),
                $t
            );
        }

        //This logic is similar to what symfony/doctrine-bridge/(...)/RegisterEventListenersAndSubscribersPass does
        if ($emDefClass === ContainerAwareEventManager::class) {
            $refs = $emDef->getArguments()[1] ?? [];
            $refs[] = new Reference($svcId);
        } else {
            $emDef->addMethodCall('addEventSubscriber', [new Reference($svcId)]);
        }
    }

    private function resolveEvtMgrDefinition(ContainerBuilder $container, string $svcId, string $manager): Definition
    {
        try {
            $evtMgrId = \sprintf('doctrine.orm.%s_entity_manager.event_manager', $manager);
            $emDef = $container->findDefinition($evtMgrId);
        } catch (ServiceNotFoundException $e) {
            throw new RuntimeException(
                \sprintf(
                    'Cannot register "%s" service marked with "%s" tag as an event subscriber at "%s" entity ' .
                    'manager. Unable to find entity manager event manager "%s".',
                    $svcId,
                    self::TAG_NAME,
                    $manager,
                    $evtMgrId
                ),
                $e->getCode(),
                $e
            );
        }


        try {
            $emDefClass = $emDef->getClass();
            if ($emDefClass === null && $emDef instanceof ChildDefinition) {
                $emDef = $container->findDefinition($emDef->getParent());
            }
        } catch (ServiceNotFoundException $e) {
            throw new ThisShouldNotBePossibleException(
                \sprintf(
                    'Cannot register "%s" service marked with "%s" tag as an event subscriber at "%s" entity ' .
                    'manager. Resolving doctrine bridge wrapper failed - please report this as a bug. %s',
                    $svcId,
                    self::TAG_NAME,
                    $manager,
                    HawkAuditorBundle::getBugReportStatement()
                ),
                $e->getCode(),
                $e
            );
        }

        return $emDef;
    }
}
