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

namespace OwlCorp\HawkAuditor\Helper;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\Proxy;
use OwlCorp\HawkAuditor\DTO\EntityRecord;
use OwlCorp\HawkAuditor\Exception\ThisShouldNotBePossibleException;

/**
 * @phpstan-import-type TEntity from EntityRecord
 * @phpstan-import-type TEntityProp from EntityRecord
 * @phpstan-type TDbConvertedVal scalar|null
 * @phpstan-type TForeignId array<TEntityProp, TDbConvertedVal>|array<mixed>
 * @phpstan-type TSerializedEntityId array<TEntityProp, TDbConvertedVal|TForeignId>
 */
final class DoctrineHelper
{
    /**
     * @var array<class-string, class-string> Maps entity object classes to real entity classes to resolve proxies
     */
    private array $entityToClassMap = [];

    /**
     * @var array<class-string, array<TEntityProp, Type>> Maps entities (1st level) fields (2nd level) to DBAL Type-s
     */
    private array $typeCache = [];

    /**
     * @var array<class-string, ClassMetadataInfo> Shared JIT cache of entities metadata. This cache IS SHARED across
     *                                         ALL EM/OMs. This is by design, as we extract only entity-specific meta.
     */
    public array $metaCache = [];

    /** @var \WeakMap<object, TSerializedEntityId> */
    private \WeakMap $entityIdCache;

    /**
     * @var array<class-string, bool> Map of entities which have normal flat IDs and not foreign Pks. This probably
     *                                should be saved in L2 and pre-warmed with all entities.
     */
    private array $simpleIdsEntities = [];

    public function __construct()
    {
        $this->entityIdCache = new \WeakMap();
    }

    /**
     * Translates Doctrine entity class name into a real entity class
     *
     * Usually class name will be the same. However, sometimes it will be a proxy class, in which case Doctrine
     * ClassUtils can decode it for us. This method implicit also supports aliased Doctrine class names.
     *
     * @param class-string $class
     *
     * @return class-string
     */
    public function getRealEntityClass(string $class): string
    {
        return $this->entityToClassMap[$class] ??= ClassUtils::getRealClass($class);
    }

    /** @return array<string, scalar> */
    public function dumpState(ClassMetadataInfo $metadata, object $entity): array
    {
        if ($entity instanceof Proxy) {
            $entity->__load(); //ensure entity is fully loaded (if it is it's a no-op)
        }

        $state = [];
        /** @var \ReflectionProperty $reflection */
        foreach ($metadata->getReflectionProperties() as $field => $reflection) {
            $state[$field] = $reflection->getValue($entity);
        }

        return $state;
    }

    /**
     * Converts value read directly from a mapped entity property to a scalar database-ready value
     *
     * @param AbstractPlatform  $platform   DBAL platform instance (em->getConnection()->getDatabasePlatform())
     * @param ClassMetadataInfo $entityMeta Metadata of the entity where the property ($prop) is
     * @param string            $prop       Property name
     * @param mixed             $value      Value of the field to convert to database value
     *
     */
    public function convertToDatabaseValue(
        AbstractPlatform $platform,
        ClassMetadataInfo $entityMeta,
        string $prop,
        mixed $value
    ): string|int|float|bool|null {
        if ($value === null) {
            return null;
        }
        //if this line crashes it means some special scenario wasn't accounted for and the $field isn't a column. This
        // is left here intentionally without a boundary check as a canary.
        //Also, getTypeOfField() is being removed with a suggestion to use PersisterHelper but currently there's no
        // 1:1 replacement.
        $type = $this->typeCache[$entityMeta->getName()][$prop] ?? Type::getType($entityMeta->getTypeOfField($prop));

        return $type->convertToDatabaseValue($value, $platform);
    }

    /**
     * Reads IDs for a given entity
     *
     * This method supports:
     *  1) simple scalar singular entities (most common case)
     *  2) composite scalar identifiers (e.g. one autoincrement field + one manual id)
     *  3) foreign identifiers (#Id which is a #*ToOne relationship)
     *  4) combination of all of the above in the same entity (oh, joy...)
     *
     * All identifiers will be recursively resolved to their scalar database values (see convertToDatabaseValue()).
     *
     * @param ObjectManager              $om         Manager for the entity
     * @param AbstractPlatform           $platform   DBAL platform instance (em->getConnection()->getDatabasePlatform())
     * @param TEntity                    $entity     Entity object instance
     * @param ClassMetadataInfo<TEntity> $entityMeta Metadata of the entity where the property ($prop) is
     *
     * @return TSerializedEntityId
     */
    public function getScalarEntityIds(
        ObjectManager $om,
        AbstractPlatform $platform,
        object $entity,
        ClassMetadataInfo $entityMeta
    ): array {
        if (isset($this->entityIdCache[$entity])) {
            return $this->entityIdCache[$entity];
        }

        $ids = [];
        //primary key can potentially be a composite one (i.e. has multiple columns as ID)
        // see https://www.doctrine-project.org/projects/doctrine-orm/en/2.14/tutorials/composite-primary-keys.html
        foreach ($entityMeta->getIdentifierValues($entity) as $prop => $value) {
            //a bare standard flat ID field (remember: relations are NOT fields, i.e. this is not a FK)
            if ($entityMeta->hasField($prop)) {
                //Needs normalization as this may be e.g. an UUID object!
                $ids[$prop] = $this->convertToDatabaseValue($platform, $entityMeta, $prop, $value);
            } elseif ($entityMeta->isSingleValuedAssociation($prop) && $value !== null) {
                //foreign relation as primary key in *ToOne relation, which in turn can have its own composite key...
                // which can also be foreign. If you did that to your codebase you have my condolences, but this code
                // will support this scenario too.
                // Yes, Doctrine supports that - see "Use-Case 2: Simple Derived Identity" in the link above
                //One thing which doesn't seem possible (or I wasn't able to map it) is having a foreign entity which
                // has composite key again. So this branch will always (?) return one ID anyway, but for consistency
                // it is always an array. E.g. attempting to have >1 ID on entity FakeUser with FakeAddress defining
                // "#[ORM\Id, ORM\OneToOne(targetEntity: FakeUser::class)]" will always result in
                // "Single id is not allowed on composite primary key in entity FakeUser" while generating schema.
                $foreignFqcn = $this->getRealEntityClass($value::class);
                $ids[$prop] = $this->serializeEntityIdentity(
                    $om,
                    $platform,
                    $value,
                    $this->metaCache[$foreignFqcn] ??= $om->getClassMetadata($foreignFqcn)
                );
            } else {
                //I have no idea how this can even happen?
                throw ThisShouldNotBePossibleException::reportIssue(
                    'Found unexpected related primary key which isn\'t a field nor relation - please report it.'
                );
            }
        }

        $this->entityIdCache[$entity] = $ids;
        return $ids;
    }

    /**
     * Reads IDs for a list of the same entity type
     *
     * See getScalarEntityIds() for details, as this is just a wrapper with a loop.
     *
     * @param list<TEntity>          $entities
     * @param ClassMetadataInfo<TEntity> $entityTypeMeta
     *
     * @return list<TSerializedEntityId>
     */
    public function getScalarEntitiesIds(
        ObjectManager $om,
        AbstractPlatform $platform,
        iterable $entities,
        ClassMetadataInfo $entityTypeMeta
    ): array {
        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = $this->getScalarEntityIds($om, $platform, $entity, $entityTypeMeta);
        }

        return $ids;
    }

    /**
     * Returns an array uniquely identifying an entity
     *
     * @return array<mixed> Return array with at least "#fqcn" and one more key. The "#fqcn" key contains the real
     *                      entity class, while other k=>v pairs are exactly as in getScalarEntityIds()
     *
     * Note: The real return type is something like "array{"#fqcn": string}&TSerializedEntityId" but there isn't a good
     * way to denote that return type neither in Psalm nor in PhpStan:
     *  - https://github.com/vimeo/psalm/issues/8804
     *  - https://github.com/phpstan/phpstan/issues/4703
     */
    public function serializeEntityIdentity(
        ObjectManager $om,
        AbstractPlatform $platform,
        object $entity,
        ClassMetadataInfo $entityMeta
    ): array {
        return ['#fqcn' => $entityMeta->getName()] + $this->getScalarEntityIds($om, $platform, $entity, $entityMeta);
    }

    /**
     * Iterates over a collection to get IDs attempting to avoid full collection initialization
     *
     * This method should only be called as a last resort when no diffs or anything are available. It will initialize
     * the whole collection (or even worse some deeper elements when FK PKs are used!). This method tries to be
     * semi-smart and if there are no FK PKs (which is probably like 99% use-cases of Doctrine) it will try to execute
     * just a minimal DQL for IDs only without the whole collection being loaded.
     *
     * @param TEntity                                 $entity         Entity object instance (used to read parent ID)
     * @param ClassMetadataInfo<TEntity>              $entityMeta     Metadata of the entity $collectionProp parent
     * @param string                                  $collectionProp Where collection is in the entity
     * @param PersistentCollection<int|string, mixed> $collection     Collection to work on
     *
     * @return list<TSerializedEntityId>
     */
    //phpcs:disable SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- performance-sensitive code
    public function getScalarCollectionEntitiesIds(
        ObjectManager $om,
        AbstractPlatform $platform,
        object $entity,
        ClassMetadataInfo $entityMeta,
        string $collectionProp,
        PersistentCollection $collection
    ): array {
        $targetMeta = $collection->getTypeClass();
        $targetFqcn = $targetMeta->getName();

        if (!isset($this->simpleIdsEntities[$targetFqcn])) {
            $this->simpleIdsEntities[$targetFqcn] = true;
            foreach ($targetMeta->getIdentifierFieldNames() as $idProp) {
                if (!$targetMeta->hasField($idProp)) { //normal ID
                    $this->simpleIdsEntities[$targetFqcn] = false;
                    break;
                }
            }
        }

        if ($this->simpleIdsEntities[$targetFqcn]) {
            \assert($om instanceof EntityManagerInterface);
            $selects = [];
            foreach ($targetMeta->getIdentifierFieldNames() as $idProp) {
                $selects[] = 'coll.' . $idProp;
            }
            $qb = $om->createQueryBuilder()
                     ->addSelect($selects)
                     ->from($entityMeta->getName(), 'parent')
                     ->join('parent.' . $collectionProp, 'coll')
            ;
            foreach ($entityMeta->getIdentifierValues($entity) as $idProp => $val) {
                $qb->andWhere("parent.$idProp = :prnt_$idProp")
                    ->setParameter("prnt_$idProp", $val);
            }
            return $qb->getQuery()->getScalarResult();
        }

        //we need to load the collection for non-simple IDs - there's way too much magic here to try to generate DQL
        // (~5h wasted)
        //TODO: this probably should log some notice
        $collection->initialize();
        return $this->getScalarEntitiesIds($om, $platform, $collection, $targetMeta);
    }
    //phpcs:enable
}
