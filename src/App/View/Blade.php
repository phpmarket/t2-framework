<?php

namespace App\View;

use Jenssegers\Blade\Blade as BladeView;
use T2\View;
use function app_path;
use function array_merge;
use function base_path;
use function config;
use function is_array;
use function request;
use function runtime_path;

class Blade implements View
{
    /**
     * Assign.
     *
     * @param string|array $name
     * @param mixed        $value
     */
    public static function assign(string|array $name, mixed $value = null): void
    {
        $request = request();
        $request->_view_vars = array_merge((array)$request->_view_vars, is_array($name) ? $name : [$name => $value]);
    }

    /**
     * Render.
     *
     * @param string      $template
     * @param array       $vars
     * @param string|null $app
     * @param string|null $plugin
     *
     * @return string
     */
    public static function render(string $template, array $vars, ?string $app = null, ?string $plugin = null): string
    {
        static $views = [];
        $request = request();
        $plugin = $plugin === null ? ($request->plugin ?? '') : $plugin;
        $app = $app === null ? ($request->app ?? '') : $app;
        $configPrefix = $plugin ? "plugin.$plugin." : '';
        $baseViewPath = $plugin ? base_path() . "/plugin/$plugin/app" : app_path();
        if ($template[0] === '/') {
            if (str_contains($template, '/view/')) {
                [$viewPath, $template] = explode('/view/', $template, 2);
                $viewPath = base_path("$viewPath/view");
            } else {
                $viewPath = base_path();
                $template = ltrim($template, '/');
            }
        } else {
            $viewPath = $app === '' ? "$baseViewPath/view" : "$baseViewPath/$app/view";
        }
        if (!isset($views[$viewPath])) {
            $views[$viewPath] = new BladeView($viewPath, runtime_path() . '/views');
            $extension = config("{$configPrefix}view.extension");
            if ($extension) {
                $extension($views[$viewPath]);
            }
        }
        if (isset($request->_view_vars)) {
            $vars = array_merge((array)$request->_view_vars, $vars);
        }
        return $views[$viewPath]->render($template, $vars);
    }
}
