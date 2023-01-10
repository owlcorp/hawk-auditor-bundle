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

use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use OwlCorp\HawkAuditor\Entity\Doctrine\EntityAuditRecord;
use OwlCorp\HawkAuditor\Exception\InvalidArgumentException;
use OwlCorp\HawkAuditor\Exception\LogicException;
use OwlCorp\HawkAuditor\Factory\SymfonyChangesetFactory;
use OwlCorp\HawkAuditor\Filter\Changeset\DoctrineStateMarshaller;
use OwlCorp\HawkAuditor\Processor\FilteredProcessor;
use OwlCorp\HawkAuditor\Producer\AuditProducer;
use OwlCorp\HawkAuditor\Producer\Doctrine\DoctrineAlterProducer;
use OwlCorp\HawkAuditor\Producer\Doctrine\DoctrineAccessProducer;
use OwlCorp\HawkAuditor\Sink\AuditSink;
use OwlCorp\HawkAuditor\Sink\DoctrineOrmSink;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Below is a typedef for the whole processed config, to ensure some guarantees which the Extension class can depend on.
 *
 * @phpstan-type TServiceId string
 * @phpstan-type TFilterableType class-string
 * @phpstan-type TWildcardFqcnType Configuration::WILDCARD_FQCN_TYPE
 * @phpstan-type TFieldFilter array<TWildcardFqcnType, non-empty-list<string>>|array<class-string, list<string>|null>
 * @phpstan-type THandler array<string, array<string, mixed>|null>
 * @phpstan-type TPipelineConfig
 * array{
 *  producers: list<THandler>,
 *  processor: TServiceId,
 *  processor: TServiceId,
 *  filters: array{
 *      default: array{audit_type: bool, audit_field: bool},
 *      autoconfigure: array{enabled: bool, priority: int},
 *      only_include_types: list<TFilterableType>,
 *      only_exclude_types: list<TFilterableType>,
 *      include_types: list<TFilterableType>,
 *      exclude_types: list<TFilterableType>,
 *      only_include_fields: TFieldFilter,
 *      only_exclude_fields: TFieldFilter,
 *      include_fields: TFieldFilter,
 *      exclude_fields: TFieldFilter,
 *      doctrine_changeset_marshaller: array{enabled: bool|null},
 *  },
 *  sinks: list<THandler>
 * }>
 *
 * @phpstan-type TConfigTpl array<string, TPipelineConfig>
 */
final class Configuration implements ConfigurationInterface
{
    private const WILDCARD_FQCN_TYPE = '_any_';

    //Regex via PHP docs: https://www.php.net/manual/en/language.variables.basics.php
    private const PHP_FIELD_REGEX = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D';

    //Some producers and sinks require optional packages to be installed, without them attempt to even load their
    // classes lead to a fatal error (as they e.g. implement external interfaces)
    /** @var array<class-string, array{0: class-string, string}>  */
    private const OPTIONAL_REQUIREMENTS = [
        DoctrineAccessProducer::class => [Events::class, 'doctrine/orm'],
        DoctrineAlterProducer::class => [Events::class, 'doctrine/orm'],
        DoctrineOrmSink::class => [ManagerRegistry::class, 'doctrine/orm'],
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(HawkAuditorExtension::BUNDLE_ALIAS);
        $root = $treeBuilder->getRootNode();

        $root
            ->validate()
                ->always(static function(array $config): array {
                    $nameRegex = '/^' . HawkAuditorExtension::PIPELINE_NAME_PATTERN . '$/D';
                    foreach (array_keys($config) as $name) {
                        if (\preg_match($nameRegex, $name) === 1) {
                            continue;
                        }

                        throw new InvalidArgumentException(
                            \sprintf(
                                'Pipeline/group name "%s" is invalid. It should contain only alphanumeric characters ' .
                                'and underscores (matching "%s")',
                                $name,
                                $nameRegex
                            )
                        );
                    }

                    return $config;
                })
            ->end()
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->append($this->addProducers())
                ->children()
                    ->scalarNode('changeset_factory')
                        ->info('Changeset factory service id. Leave as null to autoconfigure ' .
                               SymfonyChangesetFactory::class)
                        ->defaultNull()
                    ->end()
                    ->scalarNode('processor')
                        ->info('Processor service id. Leave as null to autoconfigure ' . FilteredProcessor::class)
                        ->defaultNull()
                    ->end()
                ->end()
                ->append($this->addFilters())
                ->append($this->addSinks())
                ->validate()
                    ->always(static function(?array $config): array {
                        $config ??= [];

                        //If no producers are configured and Doctrine can be used we add it as a sane default.
                        //Empty producers section is a (weird but still) valid configuration.
                        if (!isset($config['producers'])) {
                            $config['producers'] = self::getMissingReqs(DoctrineAlterProducer::class) === null
                                ? [[HawkAuditorExtension::getProducerShortAlias(DoctrineAlterProducer::class) => null]]
                                : [];
                        }

                        //If no sinks are configured and Doctrine can be used we add it as a sane default
                        //Empty sinks section is a (weird but still) valid configuration.
                        if (!isset($config['sinks'])) {
                            $config['sinks'] = self::getMissingReqs(DoctrineOrmSink::class) === null
                                ? [[HawkAuditorExtension::getSinkShortAlias(DoctrineOrmSink::class) => null]]
                                : [];
                        }

                        return $config;
                    })
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * Builds the following sections of the config:
     *  - hawk_auditor.<group>.producers.*
     */
    private function addProducers(): NodeDefinition
    {
        $treeBuilder = new TreeBuilder('producers');

        $node = $treeBuilder->getRootNode()
            ->info(
                \sprintf(
                    'List of services which offer events for this audit pipeline; ' .
                    'you can use built-in ones or specify your own service name. If you have Doctrine ORM installed ' .
                    'and omit this section or set it to ~/null (not empty!), "%s" (%s) with a default EM will be ' .
                    'added for you',
                    HawkAuditorExtension::getProducerShortAlias(DoctrineAlterProducer::class),
                    DoctrineAlterProducer::class
                )
            )
            //this is done so that validation in getConfigTreeBuilder() can detect section not being present at all vs.
            // being left empty by the user intentionally. Do NOT change this to "addDefaultsIfNotSet()" as this will
            // make this section an empty array, making it impossible to distinguish "not set" vs "empty" in config
            ->canBeUnset()
            ->beforeNormalization()
                ->always(function (mixed $children): mixed {
                    if (!\is_array($children)) {
                        return $children; //let Symfony Config deal with providing proper error message about types
                    }

                    foreach ($children as $key => $child) {
                        if (\is_string($child)) {
                            $children[$key] = [$child => null];
                        }
                    }
                    return $children;
                })
            ->end()
            ->validate()
                ->always(fn(array $producers)
                    => $this->validateHandler($producers, 'producer', HawkAuditorExtension::PRODUCERS_SHORT_ALIASES))
            ->end()
            ->children()
                ->arrayNode(HawkAuditorExtension::getProducerShortAlias(DoctrineAccessProducer::class))
                    ->info('Doctrine ORM load/access operations producer')
                    ->canBeUnset()
                    ->children()
                        ->scalarNode('manager_name')
                            ->defaultNull()
                            ->example('custom_entity_manager')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode(HawkAuditorExtension::getProducerShortAlias(DoctrineAlterProducer::class))
                    ->info('Doctrine ORM alter operations producer')
                    ->canBeUnset()
                    ->children()
                        ->scalarNode('manager_name')
                            ->defaultNull()
                            ->example('custom_entity_manager')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('App\\Audit\\ExampleCustomProducer')
                    ->info('your custom producer service implementing ' . AuditProducer::class)
                ->end()
            ->end()
            ->ignoreExtraKeys(false); //these are all non-builtin producers

        return $node;
    }

    /**
     * Builds the following sections of the config:
     *  - hawk_auditor.<group>.filters.*
     */
    private function addFilters(): NodeDefinition
    {
        $treeBuilder = new TreeBuilder('filters');

        $node = $treeBuilder->getRootNode()
            ->info('Filtering to be applied to events from producers before they are passed to sinks')
            ->addDefaultsIfNotSet()
            ->validate()
                ->always(static function($v) {
                    //Technically speaking both scenarios CAN be configured and will work... it just does not make any
                    // sense to do it this way... unless I cannot think about some scenario? In which case this
                    // validation can simply be removed, and it will just work.
                    if (\count($v['only_include_types']) > 0 && \count($v['only_exclude_types']) > 0) {
                        throw new LogicException(
                            'You can use either "only_include_types" or "only_exclude_types" - both cannot be ' .
                            'specified at the same time. Did you mean to use "include_types" and "exclude_types"?'
                        );
                    }

                    if (\count($v['only_include_fields']) > 0 && \count($v['only_exclude_fields']) > 0) {
                        throw new LogicException(
                            'You can use either "only_include_fields" or "only_exclude_fields" - both cannot be ' .
                            'specified at the same time. Did you mean to use "include_fields" and "exclude_fields"?'
                        );
                    }
                    return $v;
                })
            ->end()
            ->children()
        ;

        //Symfony config doesn't allow for appending inside of children() and PhpStorm has a bug with too long chains
        // disabling IntelliSense: https://youtrack.jetbrains.com/issue/WI-70694 - this is why it's split like that
        $node = $this->chainFiltersGeneralConfig($node);
        $node = $this->chainFiltersTypes($node);
        $node = $this->chainFilterFields($node);

        $node
                ->arrayNode('doctrine_changeset_marshaller')
                ->info(
                    \sprintf(
                        'Configuration for %s changeset filter. Unless manually changed (enable=true/false) it ' .
                        'will be enabled automatically if any of the Doctrine producers are active in this ' .
                        'pipeline.',
                        DoctrineStateMarshaller::class
                    )
                )
                ->addDefaultsIfNotSet()
                ->treatFalseLike(['enabled' => false])
                ->treatTrueLike(['enabled' => true])
                ->beforeNormalization()
                    ->ifArray()
                    ->then(function (array $v) {
                        $v['enabled'] ??= null;
                        return $v;
                    })
                ->end()
                ->children()
                ->enumNode('enabled')
                    ->info("Don't set to determine automatically")
                    ->values([true, false, null])
                    ->defaultNull()
        ;

        $node = $node->end(); //this ends ->children() opened above

        return $node;
    }

    /**
     * Builds the following sections of the config:
     *  - hawk_auditor.<group>.filters.default
     *  - hawk_auditor.<group>.filters.autoconfigure
     */
    private function chainFiltersGeneralConfig(NodeBuilder $node): NodeBuilder
    {
        return $node
                ->arrayNode('default')
                    ->info('Tie-breaker default decisions used when all filters in a group return "abstain" answer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('audit_type')->defaultTrue()->end()
                        ->booleanNode('audit_field')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('autoconfigure')
                    ->info('Autodiscovery and registration of filters based on their interfaces')
                    ->canBeDisabled()
                    ->addDefaultsIfNotSet() //currently this is redundant due canBeDisabled() but it states the intent
                    ->children()
                        ->integerNode('priority')->defaultValue(0)->end()
                    ->end()
                ->end();
    }

    /**
     * Builds the following sections of the config:
     *  - hawk_auditor.<group>.filters.only_include_types
     *  - hawk_auditor.<group>.filters.only_exclude_types
     *  - hawk_auditor.<group>.filters.include_types
     *  - hawk_auditor.<group>.filters.exclude_types
     */
    private function chainFiltersTypes(NodeBuilder $node): NodeBuilder
    {
        //TODO: all classes in _type should be validated, but it seems like config builder ignores validate() on scalar
        // prototypes which are part of concrete nodes?
        $validateClass = static function(array $cfg): array {
            $seen = [];
            foreach ($cfg as $class) {
                if (!\class_exists($class)) {
                    //Keep in mind that this does NOT consider interfaces as valid, as checking for these in filters would
                    // be very slow (and thus we expect a FQCN of an entity)
                    throw new InvalidArgumentException(
                        \sprintf('Class "%s" does not exist', $class)
                    );
                }

                if (isset($seen[$class])) { //we all miss-merged stuff ;)
                    throw new LogicException(
                        \sprintf('Class "%s" is defined more than once', $class)
                    );
                }
                $seen[$class] = true;
            }

            return $cfg;
        };

        return $node
                //Include-exclude types with forceful exclusion-inclusion of all other types
                ->arrayNode('only_include_types')
                    ->info('Include ONLY entities listed here, exclude ALL other ones, ' .
                           'and stop processing any subsequent type filters')
                    ->canBeUnset()
                    ->validate()->always($validateClass)->end()
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('only_exclude_types')
                    ->info('Exclude ONLY entities listed here, include ALL other ones, ' .
                           'and stop processing any subsequent type filters')
                    ->canBeUnset()
                    ->validate()->always($validateClass)->end()
                    ->scalarPrototype()->end()
                ->end()

                //Include-exclude with passthroughs of not configured types
                ->arrayNode('include_types')
                    ->info('Always include entities listed here, all other ones will be evaluated by lower priority' .
                           ' filters. If decision cannot be reached the filters.default.audit_types will decide')
                    ->canBeUnset()
                    ->validate()->always($validateClass)->end()
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('exclude_types')
                    ->info('Always exclude entities listed here, all other ones will be evaluated by lower priority' .
                           ' filters. If decision cannot be reached the filters.default.audit_types will decide')
                    ->canBeUnset()
                    ->validate()->always($validateClass)->end()
                    ->scalarPrototype()->end()
                ->end();
    }

    /**
     * Builds the following sections of the config:
     *  - hawk_auditor.<group>.filters.only_include_fields
     *  - hawk_auditor.<group>.filters.only_exclude_fields
     *  - hawk_auditor.<group>.filters.include_fields
     *  - hawk_auditor.<group>.filters.exclude_fields
     */
    private function chainFilterFields(NodeBuilder $node): NodeBuilder
    {
        $processAndValidateClassField = static function(array $cfg): array {
            $newCfg = []; //we create a new one to not accidentally overwrite something

            foreach ($cfg as $class => $fields) {
                //not a real class - it is a marker for "any class", so only a static validation is possible
                if ($class === self::WILDCARD_FQCN_TYPE) {
                    if (\count($fields) === 0) {
                        throw new InvalidArgumentException(
                            \sprintf(
                                'When using a wildcard class ("%s") you need to define at least one field',
                                self::WILDCARD_FQCN_TYPE
                            )
                        );
                    }

                    foreach ($fields as $name) {
                        if (\preg_match(self::PHP_FIELD_REGEX, $name) === 1) {
                            continue;
                        }

                        throw new InvalidArgumentException(
                            \sprintf(
                                'Field name "%s" defined for wildcard class ("%s") is invalid',
                                $name,
                                self::WILDCARD_FQCN_TYPE
                            )
                        );
                    }

                    $newCfg[''] = $fields; //everything else expects empty class name, the _any_ is for a better UX
                    continue;
                }

                //If it's a real class we can just analyze it with reflections and ensure there are no typos
                //Keep in mind that this does NOT consider interfaces as valid, as checking for these in filters would
                // be very slow (and thus we expect a FQCN of an entity)
                $classRef = new \ReflectionClass((string)$class); //this will error-out w/ good clas not found error
                foreach ($fields as $name) {
                    if (!$classRef->hasProperty($name)) {
                        throw new InvalidArgumentException(
                            \sprintf('Class "%s" has no property named "%s"', $class, $name)
                        );
                    }
                }

                $newCfg[$class] = $fields;
            }

            return $newCfg;
        };

        //Note: field names are not validated via regex unlike in the types filters as we validate if they exist in
        //      classes as a whole
        return $node
                //Include-exclude fields of types with forceful exclusion-inclusion of all other types
                ->arrayNode('only_include_fields')
                ->info(
                    \sprintf(
                        'Include ONLY specified fields from entities listed here, exclude ALL other fields from, ' .
                        'any other entities and stop processing any subsequent type filters. A special "%s" ' .
                        'pseudo-classname can be used as a placeholder for any entity class.',
                        self::WILDCARD_FQCN_TYPE
                    )
                )
                ->validate()->always($processAndValidateClassField)->end()
                    ->arrayPrototype()
                        ->scalarPrototype()->end()
                    ->end()
                ->end() //end of only_include_fields

                ->arrayNode('only_exclude_fields')
                    ->info(
                        \sprintf(
                            'Exclude ONLY specified fields from entities listed here, include ALL other fields from, ' .
                           'any other entities and stop processing any subsequent type filters. A special "%s" ' .
                           'pseudo-classname can be used as a placeholder for any entity class.',
                            self::WILDCARD_FQCN_TYPE
                        )
                    )
                    ->canBeUnset()
                    ->validate()->always($processAndValidateClassField)->end()
                    ->arrayPrototype()
                        ->scalarPrototype()->end()
                    ->end()
                ->end() //end of only_exclude_fields


                //Include-exclude with passthroughs of not configured types
                ->arrayNode('include_fields')
                    ->info(
                        \sprintf(
                            'Always include fields from entities listed here, all other ones will be evaluated by ' .
                           'lower priority filters. If decision cannot be reached the filters.default.audit_field ' .
                           'will decide. A special "%s" pseudo-classname can be used as a placeholder for any ' .
                           'entity class.',
                            self::WILDCARD_FQCN_TYPE
                        )
                    )
                    ->canBeUnset()
                    ->validate()->always($processAndValidateClassField)->end()
                    ->arrayPrototype()
                        ->scalarPrototype()->end()
                    ->end()
                ->end() //end of include_fields

                ->arrayNode('exclude_fields')
                    ->info(
                        \sprintf(
                            'Always exclude fields from entities listed here, all other ones will be evaluated by ' .
                           'lower priority filters. If decision cannot be reached the filters.default.audit_field ' .
                           'will decide. A special "%s" pseudo-classname can be used as a placeholder for any ' .
                           'entity class.',
                            self::WILDCARD_FQCN_TYPE
                        )
                    )
                    ->validate()->always($processAndValidateClassField)->end()
                    ->arrayPrototype()
                        ->scalarPrototype()->end()
                    ->end()
                ->end(); //end of exclude_fields
    }

    /**
     * Builds the following sections of the config:
     *  - hawk_auditor.<group>.sinks.*
     */
    private function addSinks(): NodeDefinition
    {
        $treeBuilder = new TreeBuilder('sinks');

        $node = $treeBuilder->getRootNode()
            ->info(
                \sprintf(
                    'List of services which are responsible for storing events for this audit pipeline; ' .
                    'you can use built-in ones or specify your own service name. If you have Doctrine ORM installed ' .
                    'and omit this section or set it to ~/null (not empty!), "%s" (%s) with a default EM will be ' .
                    'added for you',
                    HawkAuditorExtension::getSinkShortAlias(DoctrineOrmSink::class),
                    DoctrineOrmSink::class
                )
            )
            //this is done so that validation in getConfigTreeBuilder() can detect section not being present at all vs.
            // being left empty by the user intentionally. Do NOT change this to "addDefaultsIfNotSet()" as this will
            // make this section an empty array, making it impossible to distinguish "not set" vs "empty" in config
            ->canBeUnset()
            ->beforeNormalization()
                ->always(function (mixed $children): mixed {
                    if (!\is_array($children)) {
                        return $children; //let Symfony Config deal with providing proper error message about types
                    }

                    foreach ($children as $key => $child) {
                        if (\is_string($child)) {
                            $children[$key] = [$child => null];
                        }
                    }
                    return $children;
                })
            ->end()
            ->validate()
                ->always(fn(array $sinks)
                    => $this->validateHandler($sinks, 'sink', HawkAuditorExtension::SINKS_SHORT_ALIASES))
            ->end()
            ->children()
                ->arrayNode(HawkAuditorExtension::getSinkShortAlias(DoctrineOrmSink::class))
                    ->info(\sprintf('Doctrine ORM sink using the "%s" entity', EntityAuditRecord::class))
                    ->canBeUnset()
                    ->children()
                        ->scalarNode('manager_name')
                            ->defaultNull()
                            ->example('custom_entity_manager')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('App\\Audit\\ExampleCustomSink')
                    ->info('your custom producer service implementing ' . AuditSink::class)
                ->end()
            ->end()
            ->ignoreExtraKeys(false); //these are all non-builtin producers

        return $node;
    }

    private function validateHandler(array $items, string $categoryName, array $aliasMap): array
    {
        foreach ($items as $params) {
            //Verify that only one handler is defined per array row
            if (\count($params) !== 1) {
                throw new InvalidArgumentException(
                    \sprintf(
                        'Expected exactly one key with %s name, but found %d ("%s")',
                        $categoryName,
                        \count($params),
                        \implode('", "', \array_keys($params))
                    )
                );
            }

            //Verify no parameters are defined for non-builtin handlers
            $name = \array_key_first($params);
            $isBuiltinAlias = isset($aliasMap[$name]);
            if ($params[$name] !== null && !$isBuiltinAlias) {
                $found = \is_array($params[$name]) ? \implode($params[$name]) : $params[$name];

                throw new InvalidArgumentException(
                    \sprintf(
                        '%s "%s" is not one of the built-in ones ("%s"), so it cannot contain options but found "%s"',
                        $categoryName,
                        $name,
                        \implode('", "', \array_keys($aliasMap)),
                        $found
                    )
                );
            }

            //Built-in handlers may have some requirements which are optional packages
            if ($isBuiltinAlias &&
                ($reqs = self::getMissingReqs($aliasMap[$name])) !== null) {
                throw new LogicException(
                    \sprintf(
                        '"%s" %s cannot be enabled at the moment as "%s" is not installed. Try running "composer ' .
                        'require %3$s".',
                        $name, $categoryName, $reqs
                    )
                );
            }
        }

        return $items;
    }

    private static function getMissingReqs(?string $fqcn): ?string
    {
        //we don't really care here for validating if there are no requirements or all are met
        if (!isset(self::OPTIONAL_REQUIREMENTS[$fqcn])) {
            return null;
        }

        $reqs = self::OPTIONAL_REQUIREMENTS[$fqcn];
        return \class_exists($reqs[0]) || \interface_exists($reqs[0]) ? null : $reqs[1];
    }
}
