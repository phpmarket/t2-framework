<?php

namespace App;

use function config;
use function request;

class View
{
    /**
     * Assign.
     *
     * @param mixed $name
     * @param mixed $value
     *
     * @return void
     */
    public static function assign($name, mixed $value = null): void
    {
        $request = request();
        $plugin = $request->plugin ?? '';
        $handler = config($plugin ? "plugin.$plugin.view.handler" : 'view.handler');
        $handler::assign($name, $value);
    }
}