<?php

namespace App;

use T2\Config;

/**
 * Class Container
 *
 * @package support
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
        $plugin = \T2\App::getPluginByClass($name);
        return static::instance($plugin)->{$name}(... $arguments);
    }

}
