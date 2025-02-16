<?php

namespace App;

use T2\App;
use T2\Config;

/**
 * Class Container
 *
 * @package App
 * @method static mixed get($name)
 * @method static mixed make($name, array $parameters)
 * @method static bool has($name)
 */
class Container
{
    /**
     * Instance
     *
     * @param string $plugin
     *
     * @return array|mixed|void|null
     */
    public static function instance(string $plugin = '')
    {
        return Config::get($plugin ? "plugin.$plugin.container" : 'container');
    }

    /**
     * @param string $name
     * @param array  $arguments
     *
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        $plugin = App::getWebByClass($name);
        return static::instance($plugin)->{$name}(... $arguments);
    }

}
