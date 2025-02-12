<?php

namespace T2;

use Psr\Container\ContainerInterface;
use T2\Exception\NotFoundException;
use function array_key_exists;
use function class_exists;

class Container implements ContainerInterface
{
    /**
     * @var array
     */
    protected array $instances = [];

    /**
     * @var array
     */
    protected array $definitions = [];

    /**
     * Get
     *
     * @param string $id
     *
     * @return mixed|string
     * @throws NotFoundException
     */
    public function get(string $id): mixed
    {
        if (!isset($this->instances[$id])) {
            if (isset($this->definitions[$id])) {
                $this->instances[$id] = call_user_func($this->definitions[$id], $this);
            } else {
                if (!class_exists($id)) {
                    throw new NotFoundException("Class '$id' not found");
                }
                $this->instances[$id] = new $id();
            }
        }
        return $this->instances[$id];
    }

    /**
     * Has.
     *
     * @param string $id
     *
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->definitions);
    }

    /**
     * Make
     *
     * @param string $name
     * @param array  $constructor
     *
     * @return mixed|string
     * @throws NotFoundException
     */
    public function make(string $name, array $constructor = []): mixed
    {
        if (!class_exists($name)) {
            throw new NotFoundException("Class '$name' not found");
        }
        return new $name(... array_values($constructor));
    }

    /**
     * AddDefinitions.
     *
     * @param array $definitions
     *
     * @return $this
     */
    public function addDefinitions(array $definitions): Container
    {
        $this->definitions = array_merge($this->definitions, $definitions);
        return $this;
    }

}
