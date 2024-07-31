<?php

namespace Brezel\Client;

use ArrayAccess;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

class Entity implements ArrayAccess
{
    public int $id;
    public int $module_id;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @throws ReflectionException
     */
    public function __construct(Entity|array $attributes)
    {
        $this->id = $attributes['id'];
        $this->module_id = $attributes['module_id'];
        foreach ($attributes as $key => $value) {
            $this->$key = $this->parseProperty($key, $value);
        }
    }

    /**
     * @throws ReflectionException
     */
    protected function parseProperty(string $key, $value): mixed
    {
        if (!$value instanceof Entity && property_exists($this, $key)) {
            $property = new ReflectionProperty($this, $key);
            $type = $property->getType();
            if (class_exists($type->getName())) {
                $class = new ReflectionClass($type->getName());
                if ($class->getName() === Entity::class || $class->isSubclassOf(Entity::class)) {
                    return $value !== null ? $class->newInstance($value) : null;
                }
            }
        }
        return $value;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->$offset;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->$offset = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->$offset);
    }
}
