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

namespace OwlCorp\HawkAuditor\DependencyInjection;

use OwlCorp\HawkAuditor\DependencyInjection\HawkAuditorExtension as Extension;
use OwlCorp\HawkAuditor\Exception\InvalidArgumentException;
use OwlCorp\HawkAuditor\Exception\LogicException;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/** @internal  */
final class InjectionHelper
{
    private const CONSTRUCTOR_INJECTION = 0;
    private const SETTER_INJECTION = 1;

    /**
     * @phpstan-type TInjectable class-string
     * @phpstan-type TInjectee class-string
     * @phpstan-type TInjectionType int
     * @phpstan-type TArgNum int
     * @phpstan-type TSetterName
     *
     * @var array<TInjectable, array<TInjectee, array{TInjectionType, TArgNum, ?TSetterName}>>
     */
    private array $injectionCache = [];

    public function injectReferenceIntoDefinition(
        string $injectableId,
        string $injectableClass,
        string $pipeline,
        string $injecteeId,
        Definition $injecteeDef
    ): void {
        $injecteeClass = $injecteeDef->getClass();
        if ($injecteeClass === null) {
            throw new LogicException(
                \sprintf(
                    'Service "%s" configured in %s configuration of "%s" pipeline/group has no class defined.',
                    $injecteeId,
                    Extension::BUNDLE_ALIAS,
                    $pipeline
                )
            );
        }

        if (!isset($this->injectionCache[$injecteeClass])) {
            $this->guessInjectionMethod($injectableId, $injectableClass, $pipeline, $injecteeId, $injecteeClass);
        }

        match($this->injectionCache[$injectableClass][$injecteeClass][0]) {
            self::CONSTRUCTOR_INJECTION =>
            $injecteeDef->setArgument(
                $this->injectionCache[$injectableClass][$injecteeClass][1],
                new Reference($injectableId)
            ),
            self::SETTER_INJECTION =>
            $injecteeDef->addMethodCall(
                $this->injectionCache[$injectableClass][$injecteeClass][2],
                [$this->injectionCache[$injectableClass][$injecteeClass][1] => new Reference($injectableId)]
            ),
        };
    }

    private function guessInjectionMethod(
        string $injectableId,
        string $injectableClass,
        string $pipeline,
        string $injecteeId,
        string $injecteeClass): void
    {
        $ref = new \ReflectionClass($injecteeClass);
        $paramNum = $this->findMethodInjectionCandidate($ref, '__construct', $injectableClass);
        if ($paramNum !== null) {
            $this->injectionCache[$injectableClass][$injecteeClass] = [self::CONSTRUCTOR_INJECTION, $paramNum];
            return;
        }

        $setterName = 'set' . (new \ReflectionClass($injectableClass))->getShortName();
        if (str_ends_with($setterName, 'Interface')) {
            $setterName = \substr($setterName, 0, -9);
        }

        $paramNum = $this->findMethodInjectionCandidate($ref, $setterName, $injectableClass);
        if ($paramNum !== null) {
            $this->injectionCache[$injectableClass][$injecteeClass] = [self::SETTER_INJECTION, $paramNum, $setterName];
            return;
        }

        throw new InvalidArgumentException(
            \sprintf(
                'Cannot provide "%1$s" ("%2$s" service) to "%3$s" ("%4$s" service), as requested by %5$s ' .
                'configuration of "%6$s" pipeline/group. "%3$s" class has no constructor parameter ' .
                'typehinting "%1$s" nor %7$s(%1$s $var) method.',
                $injectableClass, $injectableId,
                $injecteeClass, $injecteeId,
                Extension::BUNDLE_ALIAS,
                $pipeline, $setterName
            )
        );
    }

    private function findMethodInjectionCandidate(\ReflectionClass $targetRef, string $name, string $injClass): ?int
    {
        if (!$targetRef->hasMethod($name)) { //no method => no injection
            return null;
        }

        return $this->findParameterSpecifyingType($targetRef->getMethod($name), $injClass);
    }

    private function findParameterSpecifyingType(\ReflectionMethod $methodRef, string $type): ?int
    {
        foreach ($methodRef->getParameters() as $num => $parameter) {
            if ($this->parameterSpecifiesSubtype($parameter, $type)) {
                return $num;
            }
        }

        return null;
    }

    public function parameterAcceptsType(\ReflectionParameter $parameter, string $type): bool
    {
        $paramType = $parameter->getType();

        return $paramType === null || $this->isContravariant($paramType, $type);
    }

    public function parameterSpecifiesSubtype(\ReflectionParameter $parameter, string $type): bool
    {
        $paramType = $parameter->getType();

        return $paramType !== null && $this->isContravariant($paramType, $type);

    }

    private function isContravariant(\ReflectionType $childTyppeRef, string $parentType): bool
    {
        if ($childTyppeRef instanceof \ReflectionNamedType) { //singular type
            $childType = $childTyppeRef->getName();
            return $childType === $parentType || \is_subclass_of($childType, $parentType);
        }

        if($childTyppeRef instanceof \ReflectionUnionType) { //any of the wrapped types must match
            foreach ($childTyppeRef->getTypes() as $innerType) {
                if ($this->isContravariant($innerType, $parentType)) {
                    return true;
                }
            }

            return false;
        }

        if($childTyppeRef instanceof \ReflectionIntersectionType) { //all must match
            foreach ($childTyppeRef->getTypes() as $innerType) {
                if ($parentType = $parentType && $this->isContravariant($innerType, $parentType) === false) {
                    return false;
                }
            }

            return true;
        }

        //This can only happen in newer PHP versions when they introduce 4th+ subtype ;)
        throw new \TypeError(
            \sprintf('Found unknown "%s" subtype: "%s"', $childTyppeRef::class, \ReflectionType::class)
        );
    }
}
