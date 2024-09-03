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
    public ?array $attributes = [];

    /**
     * @throws ReflectionException
     */
    public function __construct(Entity|array $attributes)
    {
        $this->id = $attributes['id'];
        $this->module_id = $attributes['module_id'];
        foreach ($attributes as $key => $value) {
            $this->offsetSet($key, $this->parseProperty($key, $value));
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

    /**
     * Convert the entity to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $attributes = array_merge($this->attributes, get_object_vars($this));
        foreach ($attributes as $key => $value) {
            $attributes[$key] = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : $value;
            if ($key === 'attributes') {
                unset($attributes[$key]);
            }
        }
        return array_merge(
            [
                'id' => $this->id,
                'module_id' => $this->module_id,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
            $attributes
        );
    }

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset) || array_key_exists($offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->$offset ?? $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (property_exists($this, $offset)) {
            $this->$offset = $value;
        } else {
            $this->attributes[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (property_exists($this, $offset)) {
            $this->$offset = null;
        } else {
            unset($this->attributes[$offset]);
        }
    }
}
