<?php

namespace Doctrine\Common\Util;

class ClassUtils
{
    /**
     * @param class-string $class
     * @return class-string
     */
    public static function getRealClass(string $class): string {}

    /**
     * @template T of object
     * @param T $object
     * @return class-string<T>
     */
    public static function getClass(object $object): string {}

    /**
     * @param class-string $className
     * @return class-string
     */
    public static function getParentClass(string $className): string {}

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return \ReflectionClass<T>
     */
    public static function newReflectionClass(string $class): \ReflectionClass {}

    /**
     * @template T of object
     * @param T $object
     * @return \ReflectionClass<T>
     */
    public static function newReflectionObject(object $object): \ReflectionClass {}

    /**
     * @param class-string $className
     * @param string $proxyNamespace
     * @return class-string
     */
    public static function generateProxyClassName(string $className, string $proxyNamespace): string {}
}
