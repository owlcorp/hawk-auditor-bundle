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

namespace OwlCorp\HawkAuditor\DependencyInjection;

use OwlCorp\HawkAuditor\DependencyInjection\Compiler\DoctrineEvtSubscribersPass;
use OwlCorp\HawkAuditor\DependencyInjection\Compiler\RegisterProducersPass;
use OwlCorp\HawkAuditor\Exception\RuntimeException;
use OwlCorp\HawkAuditor\Factory\SymfonyChangesetFactory;
use OwlCorp\HawkAuditor\Filter\Changeset\DoctrineStateMarshaller;
use OwlCorp\HawkAuditor\Filter\ChangesetFilter;
use OwlCorp\HawkAuditor\Filter\EntityTypeFilter;
use OwlCorp\HawkAuditor\Filter\Field\MatchFieldNameFilter;
use OwlCorp\HawkAuditor\Filter\FieldNameFilter;
use OwlCorp\HawkAuditor\Filter\FilterProvider;
use OwlCorp\HawkAuditor\Filter\Type\MatchTypeFilter;
use OwlCorp\HawkAuditor\Helper\DoctrineHelper;
use OwlCorp\HawkAuditor\Helper\SymfonySecurityHelper;
use OwlCorp\HawkAuditor\Processor\FilteredProcessor;
use OwlCorp\HawkAuditor\Producer\Doctrine\DoctrineAccessProducer;
use OwlCorp\HawkAuditor\Producer\Doctrine\DoctrineAlterProducer;
use OwlCorp\HawkAuditor\Sink\DoctrineOrmSink;
use OwlCorp\HawkAuditor\UnitOfWork\HawkUnitOfWork;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @phpstan-import-type THandler from Configuration
 * @phpstan-import-type TPipelineConfig from Configuration
 * @phpstan-import-type TConfigTpl from Configuration
 *
 * @todo make sure to validate impossible configs:
 */
final class HawkAuditorExtension extends ConfigurableExtension implements CompilerPassInterface
{
    public const PIPELINE_NAME_PATTERN = '([a-z0-9_]+)';
    public const BUNDLE_ALIAS = 'hawk_auditor';

    //Unit of Work itself
    private const UOW_SVC_ID = self::BUNDLE_ALIAS . '.%s.uow'; //vars: pipeline
    public const UOW_TAG_ID = self::BUNDLE_ALIAS . '.uow'; //tag for all units of wrk to be used by compiler pass
    public const UOW_TAG_PIPELINE_ATTR = 'pipeline'; //pipeline name stored in tag UOW_TAG_NAME attached to units of wrk

    //Helper services
    private const DOCTRINE_HELPER_SVC_ID = self::BUNDLE_ALIAS . '.doctrine_helper';
    public const PROCESSOR_SVC_ID = self::BUNDLE_ALIAS . '.%s.processor'; //vars: pipeline

    //Filters stuff
    public const FILTERS_DEFAULT_PARAM = self::BUNDLE_ALIAS . '.%s.filter_defaults'; //vars: pipeline
    public const FILTERS_PROVIDER_SVC_ID = self::BUNDLE_ALIAS . '.%s.filter_provider'; //vars: pipeline
    public const FILTER_SVC_ID = self::BUNDLE_ALIAS . '.%s.filter.%s'; //vars: pipeline; filter type
    public const FILTER_TYPE_TAG = self::BUNDLE_ALIAS . '.%s.filter.type'; //vars: pipeline
    public const FILTER_FIELD_TAG = self::BUNDLE_ALIAS . '.%s.filter.field'; //vars: pipeline
    public const FILTER_CHANGESET_TAG = self::BUNDLE_ALIAS . '.%s.filter.changeset'; //vars: pipeline

    //This is deliberately a map of short to FQCNs, as later on something may be moved and two aliases can point to
    // the same implementation (vs. you cannot have two classes using the same alias)
    public const PRODUCERS_SHORT_ALIASES = [
        'doctrine_read' => DoctrineAccessProducer::class,
        'doctrine_alter' => DoctrineAlterProducer::class,
    ];
    public const SINKS_SHORT_ALIASES = [
        'doctrine_entity' => DoctrineOrmSink::class,
    ];

    private const FILTER_FACTORY_MAP = [
        'only_include_types' => [
            'factory' => [MatchTypeFilter::class, 'includeOnMatchExcludeOtherwise'], //factory passed to DIC
            'tag' => self::FILTER_TYPE_TAG,
            'priority' => 500 //tag priority
        ],
        'only_exclude_types' => [
            'factory' => [MatchTypeFilter::class, 'excludeOnMatchIncludeOtherwise'],
            'tag' => self::FILTER_TYPE_TAG,
            'priority' => 500
        ],
        'include_types' => [
            'factory' => [MatchTypeFilter::class, 'includeOnMatchAbstainOtherwise'],
            'tag' => self::FILTER_TYPE_TAG,
            'priority' => 510
        ],
        'exclude_types' => [
            'factory' => [MatchTypeFilter::class, 'excludeOnMatchAbstainOtherwise'],
            'tag' => self::FILTER_TYPE_TAG,
            'priority' => 520
        ],
        'only_include_fields' => [
            'factory' => [MatchFieldNameFilter::class, 'includeOnMatchExcludeOtherwise'],
            'tag' => self::FILTER_FIELD_TAG,
            'priority' => 500
        ],
        'only_exclude_fields' => [
            'factory' => [MatchFieldNameFilter::class, 'excludeOnMatchIncludeOtherwise'],
            'tag' => self::FILTER_FIELD_TAG,
            'priority' => 500
        ],
        'include_fields' => [
            'factory' => [MatchFieldNameFilter::class, 'includeOnMatchAbstainOtherwise'],
            'tag' => self::FILTER_FIELD_TAG,
            'priority' => 510
        ],
        'exclude_fields' => [
            'factory' => [MatchFieldNameFilter::class, 'excludeOnMatchAbstainOtherwise'],
            'tag' => self::FILTER_FIELD_TAG,
            'priority' => 520
        ],
    ];

    /**
     * @var string Populated with service id when it is created (as needed and only once)
     */
    readonly private string $internalChangesetFactoryId;

    /**
     * @var bool Set by configureProducers; used by filters config to autoregister DoctrineStateMarshaller if needed
     */
    readonly private bool $hasDoctrineProducers;

    public function getNamespace(): string
    {
        return 'https://owlcorp.science/schema/dic/' . self::BUNDLE_ALIAS;
    }

    /** @param TConfigTpl $mergedConfig */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        foreach ($mergedConfig as $pipeline => $config) {
            $container->log($this, \sprintf('Configuring "%s" audit pipeline "%s"', self::BUNDLE_ALIAS, $pipeline));

            $this->configureUnitOfWork($container, $pipeline, $config);
            $this->configureProducers($container, $pipeline, $config['producers']);
            $this->configureFilterParams($container, $pipeline, $config['filters']);
            $this->configureFiltersServices($container, $pipeline, $config['filters']);
            $this->configureDoctrineMarshaller($container, $pipeline, $config['filters']);
            $this->configureFiltersTags($container, $pipeline, $config['filters']);
            $this->configureSinks($container, $pipeline, $config['sinks']);
            $this->configureHelperServices($container, $pipeline);
        }
    }

    public function process(ContainerBuilder $container)
    {
        //Intentionally left blank, see https://github.com/symfony/symfony/issues/48921
    }

    /** @param TPipelineConfig $config */
    private function configureUnitOfWork(ContainerBuilder $container, string $pipeline, array $config): void
    {
        $uowName = \sprintf(self::UOW_SVC_ID, $pipeline);
        $container->log(
            $this,
            \sprintf('Registering Unit of Work (%s<%s>) for pipeline "%s"', $uowName, HawkUnitOfWork::class, $pipeline)
        );

        $uow = $container->register($uowName, HawkUnitOfWork::class);
        $uow->setArgument(
            '$changesetFactory',
            new Reference($config['changeset_factory'] ?? $this->getChangesetFactory($container))
        );
        $uow->setArgument(
            '$auditProcessor',
            $this->configureProcessor($container, $pipeline, $config['processor'])
        );
        //$auditSink is not set here, since we don't know if a user didn't register any using tags, which will be
        // resolved later, and thus need a compiler pass
        $uow->addTag(self::UOW_TAG_ID, [self::UOW_TAG_PIPELINE_ATTR => $pipeline]);
    }

    private function getChangesetFactory(ContainerBuilder $container): string
    {
        //Changeset factory is shared across all pipelines, so once we did it once we don't have to do it again
        if (isset($this->internalChangesetFactoryId)) {
            return $this->internalChangesetFactoryId;
        }

        $symfonySecHelperId = self::BUNDLE_ALIAS . '.security_helper';
        $symfonySecHelper =  $container->register($symfonySecHelperId, SymfonySecurityHelper::class);
        $symfonySecHelper->setArgument(
            '$tokenStorage',
            new Reference(TokenStorageInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)
        );

        $this->internalChangesetFactoryId = self::BUNDLE_ALIAS . '.changeset_factory';
        $symfonySetFct = $container->register($this->internalChangesetFactoryId, SymfonyChangesetFactory::class);
        $symfonySetFct->setArgument(
            RequestStack::class,
            new Reference('$requestStack', ContainerInterface::NULL_ON_INVALID_REFERENCE)
        );
        $symfonySetFct->setArgument('$securityHelper', new Reference($symfonySecHelperId));

        $container->log(
            $this,
            \sprintf(
                'Using default changeset factory "%s" (class: "%s")',
                $this->internalChangesetFactoryId,
                $symfonySetFct->getClass()
            )
        );

        return $this->internalChangesetFactoryId;
    }

    private function configureProcessor(ContainerBuilder $container, string $pipeline, ?string $processorCfg): Reference
    {
        $processorSvcId = \sprintf(self::PROCESSOR_SVC_ID, $pipeline);
        if ($processorCfg !== null) {
            $container->log($this, \sprintf('Aliasing processor "%s" to "%s"', $processorSvcId, $processorCfg));

            $container->setAlias($processorSvcId, new Reference($processorCfg));
            return new Reference($processorSvcId);
        }

        $container->log(
            $this,
            \sprintf('Creating processor "%s" for class "%s"', $processorSvcId, FilteredProcessor::class)
        );

        $processor = $container->register($processorSvcId, FilteredProcessor::class);
        $processor->setArgument(
            CacheInterface::class,
            new Reference(CacheInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)
        );
        //Rest of the arguments will be attached in a compiler pass, as we also need to handle custom processors.
        // We cannot resolve it now as we don't know if the user's processor supports FilterAwareProcessor


        return new Reference($processorSvcId);
    }

    private function configureProducers(ContainerBuilder $container, string $pipeline, array $producersCfg): void
    {
        $producers = [];
        foreach($producersCfg as $producer) {
            //This is a peculiar structure where each $producer is just one key with a name. This is done so that the
            // same producer type can be configured multiple times with a different set of options (e.g. for two EMs)
            $name = \array_key_first($producer);
            $config = $producer[$name] ?? [];

            //Out built-in producers don't get any special treatment - they're just created automatically by this
            // extension as opposed to being configured by the user in services config, i.e. they work in the same
            // way as any custom producer. However, we're not adding them to $producers here as the compiler pass
            // looks at both tags AND in the custom parameter.
            if (isset(self::PRODUCERS_SHORT_ALIASES[$name])) {
                $this->configureDoctrineProducer($container, $pipeline, $name, $config['manager_name'] ?? 'default');
                $this->hasDoctrineProducers ??= true;

            } else {
                //This $container has only parameters, only a compiler pass can look for user services, so we have to
                // add a special parameter which will be resolved later in a compiler pass.
                $producers[] = $name;
                $container->log($this, \sprintf('Pipeline "%s" enqueueing "%s" custom producer', $pipeline, $name));
            }
        }

        $this->hasDoctrineProducers ??= false;
        $container->setParameter(\sprintf(RegisterProducersPass::UOW_PARAM_UNTAGGED_PRODUCERS, $pipeline), $producers);
    }

    private function configureDoctrineProducer(
        ContainerBuilder $container,
        string $pipeline,
        string $producer,
        string $emName
    ): void {
        $producerSvcName = \sprintf(RegisterProducersPass::SVC_ID, $pipeline, $producer);
        $tagName = \sprintf(RegisterProducersPass::SVC_ID, $pipeline, $producer);
        $container->log(
            $this,
            \sprintf(
                'Pipeline "%s" registering "%s" built-in producer (svc: "%s", tag: "%s") with "%s" ORM entity manager',
                $pipeline,
                $producer,
                $producerSvcName,
                $tagName,
                $emName
            )
        );

        $producer = $container->register($producerSvcName, self::PRODUCERS_SHORT_ALIASES[$producer]);
        $producer->setArgument('$dHelper', new Reference(self::DOCTRINE_HELPER_SVC_ID));
        $producer->addTag(\sprintf(Compiler\RegisterProducersPass::TAG_NAME, $pipeline));
        $producer->addTag(DoctrineEvtSubscribersPass::TAG_NAME, [DoctrineEvtSubscribersPass::TAG_EM_ATTR => $emName]);
    }

    private function configureFiltersServices(ContainerBuilder $container, string $pipeline, array $filtersCfg): void
    {
        //Configure all filter categories which users can set
        //This is slightly magical code, but all these categories are the same and provided in the config file just for
        // user's convince. They in fact map to two very simple filters (modularity ftw!) which are configured with
        // different match/not-match behavior of responses.
        foreach (self::FILTER_FACTORY_MAP as $cfgKey => $definition) {
            if (\count($filtersCfg[$cfgKey]) === 0) {
                $container->log($this, \sprintf('Pipeline "%s" has no filters for "%s"', $pipeline, $cfgKey));
                continue;
            }

            $filerId = \sprintf(self::FILTER_SVC_ID, $pipeline, $cfgKey);
            $filterClass = self::FILTER_FACTORY_MAP[$cfgKey]['factory'][0];
            $filter = $container->register($filerId, $filterClass);

            $factoryMethod = self::FILTER_FACTORY_MAP[$cfgKey]['factory'];
            $filterTag = \sprintf(self::FILTER_FACTORY_MAP[$cfgKey]['tag'], $pipeline);
            $filterPriority = self::FILTER_FACTORY_MAP[$cfgKey]['priority'];
            $filter->setFactory($factoryMethod);
            $filter->addArgument($filterClass::createLookupIndex($filtersCfg[$cfgKey]));
            $filter->addTag($filterTag, ['priority' => $filterPriority]);

            $container->log(
                $this,
                \sprintf(
                    'Pipeline "%s" added filter svc "%s" (class: "%s") for "%s" (tag: %s@%d)',
                    $pipeline,
                    $filerId,
                    $filterClass,
                    $cfgKey,
                    $filterTag,
                    $filterPriority
                )
            );
        }
    }

    private function configureDoctrineMarshaller(ContainerBuilder $container, string $pipeline, array $filtersCfg): void
    {
        //Marshaller can be explicitly enabled in the config, or auto-enabled if any doctrine producers are registered
        if ($filtersCfg['doctrine_changeset_marshaller'] === false ||
            ($filtersCfg['doctrine_changeset_marshaller'] === null && !$this->hasDoctrineProducers)) {

            $container->log(
                $this,
                \sprintf('Pipeline "%s" will NOT use "%ss"', $pipeline, DoctrineStateMarshaller::class)
            );
            return;
        }

        //The service can be shared, as it is not specific to a pipeline (thus we don't use FILTER_SVC_ID)
        $marshallerSvcId = \sprintf(self::BUNDLE_ALIAS . '.doctrine_changeset_marshaller');
        if ($container->has($marshallerSvcId)) {
            $marshaller = $container->getDefinition($marshallerSvcId);
        } else {
            $marshaller = $container->register($marshallerSvcId, DoctrineStateMarshaller::class);
            $marshaller->setArgument('$dHelper', new Reference(self::DOCTRINE_HELPER_SVC_ID));
        }
        $marshaller->addTag(\sprintf(self::FILTER_CHANGESET_TAG, $pipeline));

        $container->log(
            $this,
            \sprintf(
                'Pipeline "%s" added special changeset filter "%s" (svc: "%s")',
                $pipeline,
                DoctrineStateMarshaller::class,
                $marshallerSvcId
            )
        );
    }

    private function configureFilterParams(ContainerBuilder $container, string $pipeline, array $filtersCfg): void
    {
        //Get processor defaults into container, so they can be used to configure processor in a compiler pass
        //While this is in the "filters" section of the config, as it makes more sense from the user's perspective,
        // this pertains more to the processor.
        //While we can be sure about our processor existing in this container, we know for sure user's custom processor
        // will not exist there, so we need to do it in a compiler pass
        $container->setParameter(
            \sprintf(self::FILTERS_DEFAULT_PARAM, $pipeline),
            [
                '$defaultAuditType' => $filtersCfg['default']['audit_type'],
                '$defaultAuditField' => $filtersCfg['default']['audit_field'],
            ]
        );
    }

    private function configureFiltersTags(ContainerBuilder $container, string $pipeline, array $filtersCfg): void
    {
        //Configure automatic tagging if desired for this pipeline
        $typeFilterTag = \sprintf(self::FILTER_TYPE_TAG, $pipeline);
        $fieldFilterTag = \sprintf(self::FILTER_FIELD_TAG, $pipeline);
        $changesetFilterTag = \sprintf(self::FILTER_CHANGESET_TAG, $pipeline);
        if ($filtersCfg['autoconfigure']['enabled']) {
            $attrs = ['priority' => $filtersCfg['autoconfigure']['priority']];
            $container->registerForAutoconfiguration(EntityTypeFilter::class)->addTag($typeFilterTag, $attrs);
            $container->registerForAutoconfiguration(FieldNameFilter::class)->addTag($fieldFilterTag, $attrs);
            $container->registerForAutoconfiguration(ChangesetFilter::class)->addTag($changesetFilterTag, $attrs);

            $container->log(
                $this,
                \sprintf(
                    'Pipeline "%s" requested autoconfiguration of all tagged filters with priority %d',
                    $pipeline,
                    $filtersCfg['autoconfigure']['priority']
                )
            );
        }

        //Finally, configure filter provider. It will be modified in a compiler pass which configures processor, as we
        // don't know if there are any tagged services for any of the categories and the FilterProvider needs that hint
        $provider = $container->register(\sprintf(self::FILTERS_PROVIDER_SVC_ID, $pipeline), FilterProvider::class);
        $provider->setArgument(
            '$changesetFiltersIt',
            new TaggedIteratorArgument(\sprintf(self::FILTER_CHANGESET_TAG, $pipeline))
        );
        $provider->setArgument(
            '$entityTypeFiltersIt',
            new TaggedIteratorArgument(\sprintf(self::FILTER_TYPE_TAG, $pipeline))
        );
        $provider->setArgument(
            '$fieldNameFiltersIt',
            new TaggedIteratorArgument(\sprintf(self::FILTER_TYPE_TAG, $pipeline))
        );
    }

    private function configureSinks(ContainerBuilder $container, string $pipeline, array $sinksCfg): void
    {
        $sinks = [];
        foreach($sinksCfg as $sink) {
            //This is a peculiar structure where each $sink is just one key with a name. This is done so that the
            // same sink type can be configured multiple times with a different set of options (e.g. for two EMs)
            $name = \array_key_first($sink);
            $config = $sink[$name] ?? [];

            //Out built-in sinks don't get any special treatment - they're just created automatically by this
            // extension as opposed to being configured by the user in services config, i.e. they work in the same
            // way as any custom sink. However, we're not adding them to $sinks here as the compiler pass
            // looks at both tags AND in the custom parameter.
            if (isset(self::SINKS_SHORT_ALIASES[$name])) {
                $this->configureDoctrineSink($container, $pipeline, $name, $config['manager_name'] ?? 'default');

            } else {
                //This $container has only parameters, only a compiler pass can look for user services, so we have to
                // add a special parameter which will be resolved later in a compiler pass.
                $sinks[] = $name;
                $container->log($this, \sprintf('Pipeline "%s" enqueueing "%s" custom sink', $pipeline, $name));
            }
        }

        $container->setParameter(\sprintf(Compiler\RegisterSinksPass::UOW_PARAM_UNTAGGED_SINKS, $pipeline), $sinks);
    }

    private function configureDoctrineSink(
        ContainerBuilder $container,
        string $pipeline,
        string $sink,
        string $em
    ): void {
        $sinkSvcName = \sprintf(Compiler\RegisterSinksPass::SVC_ID, $pipeline, $sink);
        $tagName = \sprintf(Compiler\RegisterSinksPass::SVC_ID, $pipeline, $sink);
        $emSvcName = \sprintf('doctrine.orm.%s_entity_manager', $em);
        $container->log(
            $this,
            \sprintf(
                'Pipeline "%s" registering "%s" built-in producer (svc: "%s", tag: "%s") with "%s" OM (svc: "%s")',
                $pipeline,
                $sink,
                $sinkSvcName,
                $tagName,
                $em,
                $emSvcName
            )
        );

        $sink = $container->register($sinkSvcName, self::SINKS_SHORT_ALIASES[$sink]);
        $sink->setArgument('$em', new Reference($emSvcName));
        $sink->addTag(\sprintf(Compiler\RegisterSinksPass::TAG_NAME, $pipeline));
    }

    private function configureHelperServices(ContainerBuilder $container, string $pipeline): void
    {
        $container->register(self::DOCTRINE_HELPER_SVC_ID, DoctrineHelper::class);
    }

    public static function getProducerShortAlias(string $fqcn): string
    {
        return self::getShortAlias(self::PRODUCERS_SHORT_ALIASES, $fqcn);
    }

    public static function getSinkShortAlias(string $fqcn): string
    {
        return self::getShortAlias(self::SINKS_SHORT_ALIASES, $fqcn);
    }

    /** @param array<string, class-string> $map */
    private static function getShortAlias(array $map, string $fqcn): string
    {
        $key = \array_search($fqcn, $map, true);
        if ($key === false) {
            throw new RuntimeException(
                \sprintf('There is no alias defined for "%s" class in the map %s', $fqcn, \json_encode($map))
            );
        }

        return $key;
    }
}
