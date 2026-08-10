<?php

declare(strict_types=1);

namespace test\php\phpunit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BaseTestCase extends TestCase
{
    /**
     * Helper method to access private properties of an object using reflection.
     *
     * @param object $object The object instance.
     * @param string $property The name of the private property.
     * @return mixed The value of the private property.
     */
    protected function getPrivateProperty(object $object, string $property): mixed
    {
        $classInformation = new ReflectionClass($object);
        $classProperty = $classInformation->getProperty($property);

        return $classProperty->getValue($object);
    }

    /**
     * Helper method to invoke private methods using reflection
     *
     * @param object $object
     * @param string $methodName
     * @param array $parameters
     *
     * @return mixed
     */
    protected function invokePrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Helper method to invoke a static private method using reflection
     *
     * @param string $className
     * @param string $methodName
     * @param array $parameters
     * @return mixed
     */
    protected function invokeStaticPrivateMethod(string $className, string $methodName, array $parameters = []): mixed
    {
        $reflection = new ReflectionClass($className);
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs(null, $parameters);
    }
}
