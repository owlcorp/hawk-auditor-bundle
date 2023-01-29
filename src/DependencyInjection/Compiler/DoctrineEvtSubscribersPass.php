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
use OwlCorp\HawkAuditor\Exception\LogicException;
use OwlCorp\HawkAuditor\Exception\RuntimeException;
use OwlCorp\HawkAuditor\HawkAuditorBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Piggybacks on Symfony's DoctrineBridge lazy event listeners registration
 *
 * This needs to run BEFORE Symfony RegisterEventListenersAndSubscribersPass, as that compiler pass actually scans for
 * the tags.
 */
class DoctrineEvtSubscribersPass implements CompilerPassInterface
{
    public const TAG_NAME = HawkAuditorExtension::BUNDLE_ALIAS . '.doctrine_event_subscriber';
    public const TAG_EM_ATTR = 'manager';

    /** @var array<string, string> */
    private array $connectionSvcMap;

    /** @var array<string, string> */
    private array $emNameConnectionMap = [];

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

                $managerName = (string)$container->getParameterBag()->resolveValue($tagAttr[self::TAG_EM_ATTR]);
                $connection = $this->emNameConnectionMap[$managerName]
                              ?? $this->getConnectionForManager($container, $svcId, $managerName);
                $container->getDefinition($svcId)
                          ->addTag('doctrine.event_subscriber', ['connection' => $connection, 'priority' => 4096]);
                $container->log($this, \sprintf('Tagged "%s" as event subscriber for "%s"', $svcId, $managerName));
            }
        }
    }

    private function getConnectionForManager(ContainerBuilder $container, string $svcId, string $manager): string
    {
        $emSvcId = sprintf('doctrine.orm.%s_entity_manager', $manager); //as set in DoctrineExtension
        try {
            $emSvc = $container->findDefinition($emSvcId);
        } catch (ServiceNotFoundException $e) {
            throw new RuntimeException(
                \sprintf(
                    'Cannot register "%s" service marked with "%s" tag as an event subscriber at "%s" entity ' .
                    'manager. Unable to find entity manager "%s".',
                    $svcId,
                    self::TAG_NAME,
                    $manager,
                    $emSvcId
                ),
                $e->getCode(),
                $e
            );
        }

        if (!isset($this->connectionSvcMap) && $container->hasParameter('doctrine.connections')) {
            //This parameter is used by Symfony's Doctrine Bridge in RegisterEventListenersAndSubscribersPass and is
            // defined by the Doctrine Bundle. It contains a map of connName => connServiceId
            $this->connectionSvcMap = \array_flip($container->getParameter('doctrine.connections'));
        }

        //These shouldn't happen, unless something changes internally in Doctrine Bundle or Doctrine Bridge - connection
        // configured for the manager by definition should exist
        $emConnSvc = $emSvc->getArgument(0);
        if (!($emConnSvc instanceof Reference)) {
            $type = \gettype($emConnSvc);
            throw new LogicException(
                \sprintf(
                    'Expected entity manager service "%s" argument 0 to contain a reference to DBAL connection - ' .
                    'found "%s" instead. Please report this as a bug. ' .
                    HawkAuditorBundle::getBugReportStatement(),
                    $emSvcId,
                    $type === 'object' ? $emConnSvc::class : $type
                )
            );
        }
        $emConnSvcId = (string)$emConnSvc;
        if (!isset($this->connectionSvcMap[$emConnSvcId])) {
            throw new LogicException(
                \sprintf(
                    'Entity manager service "%s" argument 0 references DBAL connection service "%s" but such ' .
                    'connection does not map to a name. Please report this as a bug. ' .
                    HawkAuditorBundle::getBugReportStatement(),
                    $emSvcId,
                    $emConnSvcId
                )
            );
        }

        return $this->connectionSvcMap[$emConnSvcId];
    }
}
