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

use OwlCorp\HawkAuditor\DependencyInjection\HawkAuditorExtension as Extension;
use OwlCorp\HawkAuditor\Exception\LogicException;
use OwlCorp\HawkAuditor\Exception\RuntimeException;
use OwlCorp\HawkAuditor\UnitOfWork\UnitOfWork;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

abstract class AbstractUoWPass implements CompilerPassInterface
{

    final public function process(ContainerBuilder $container)
    {
        foreach ($container->findTaggedServiceIds(Extension::UOW_TAG_ID) as $uowId => $uowTagAttrs) {
            $uow = $container->getDefinition($uowId);
            $this->validateUoWTag($uow, $uowId, $uowTagAttrs);
            $this->processUoW($container, $uowId, $uow, $uowTagAttrs[0]['pipeline']);
        }
    }

    abstract protected function processUoW(
        ContainerBuilder $container,
        string $uowId,
        Definition $uow,
        string $pipeline
    ): void;


    /**
     * Technically adding tags and just searching by them seems nonsensical. However, for debugging purposes we want
     * to expose to the user of the library that these services were in fact automatically tagged and are no different
     * than built-in ones. So on dev env when debug:container is used with the tag it will show both custom and built-in
     * ones.
     *
     * @param string $listParamTpl sprintf() template for a kernel parameter containing a list of services to tag
     * @param string $tag Ready tag to apply to all services
     */
    protected function tagCustomServices(
        ContainerBuilder $container,
        string $pipeline,
        string $listParamTpl,
        string $tag
    ): void {
        foreach ($container->getParameter(\sprintf($listParamTpl, $pipeline)) as $id) {
            try {
                $svc = $container->findDefinition($id);
            } catch (ServiceNotFoundException $e) {
                throw new LogicException(
                    \sprintf(
                        'Service "%s" was used in %s configuration of "%s" pipeline/group to receive tag "%s". ' .
                        'However, this service does not exist.',
                        $id,
                        Extension::BUNDLE_ALIAS,
                        $pipeline,
                        $tag
                    ),
                    $e->getCode(),
                    $e
                );
            }

            $svc->addTag($tag);
            $container->log($this, \sprintf('Auto-tagged "%s" with "%s" in "%s" pipeline', $id, $tag, $pipeline));
        }
    }

    private function validateUoWTag(Definition $svc, string $id, array $tags): void
    {
        if (\count($tags) !== 1) {
            throw new RuntimeException(
                \sprintf(
                    'Expected "%s" ("%s") service to be tagged once with "%s" tag - found multiple',
                    $id,
                    $svc->getClass(),
                    Extension::UOW_TAG_ID
                )
            );
        }

        if (!isset($tags[0]['pipeline'])) {
            throw new RuntimeException(
                \sprintf(
                    'Expected "%s" ("%s") service to be tagged once with "%s" tag and "pipeline" attribute: ' .
                    'attribute wasn\'t found in the tag',
                    $id,
                    $svc->getClass(),
                    Extension::UOW_TAG_ID
                )
            );
        }
    }
}
