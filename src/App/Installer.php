<?php

namespace App;

use function defined;
use function is_callable;
use function method_exists;

class Installer
{
    /**
     * @param mixed $event
     *
     * @return void
     */
    public static function handlePackageInstall(mixed $event): void
    {
        // 获取当前安装的包名
        $installedPackage = $event->getOperation()->getPackage()->getName();
        $targetPackage = "phpmarket/t2-framework"; // 替换为你的插件包名
        // 仅当安装目标包时执行逻辑
        if ($installedPackage === $targetPackage) {
            // 调用实际安装逻辑（可以是私有方法）
            self::install($event);
        }
    }

    /**
     * Install.
     *
     * @param mixed $event
     *
     * @return void
     */
    public static function install(mixed $event): void
    {
        static::findHelper();
        $psr4 = static::getPsr4($event);
        foreach ($psr4 as $namespace => $path) {
            $pluginConst = "\\{$namespace}Install::IS_PLUGIN";
            if (!defined($pluginConst)) {
                continue;
            }
            $installFunction = "\\{$namespace}Install::install";
            if (is_callable($installFunction)) {
                $installFunction(true);
            }
        }
    }

    /**
     * Update.
     *
     * @param mixed $event
     *
     * @return void
     */
    public static function update(mixed $event): void
    {
        static::findHelper();
        $psr4 = static::getPsr4($event);
        foreach ($psr4 as $namespace => $path) {
            $pluginConst = "\\{$namespace}Install::IS_PLUGIN";
            if (!defined($pluginConst)) {
                continue;
            }
            $updateFunction = "\\{$namespace}Install::update";
            if (is_callable($updateFunction)) {
                $updateFunction();
                continue;
            }
            $installFunction = "\\{$namespace}Install::install";
            if (is_callable($installFunction)) {
                $installFunction(false);
            }
        }
    }

    /**
     * Uninstall.
     *
     * @param mixed $event
     *
     * @return void
     */
    public static function uninstall(mixed $event): void
    {
        static::findHelper();
        $psr4 = static::getPsr4($event);
        foreach ($psr4 as $namespace => $path) {
            $pluginConst = "\\{$namespace}Install::IS_PLUGIN";
            if (!defined($pluginConst)) {
                continue;
            }
            $uninstallFunction = "\\{$namespace}Install::uninstall";
            if (is_callable($uninstallFunction)) {
                $uninstallFunction();
            }
        }
    }

    /**
     * Get psr-4 info
     *
     * @param mixed $event
     *
     * @return array|mixed
     */
    protected static function getPsr4(mixed $event): mixed
    {
        $operation = $event->getOperation();
        $autoload = method_exists($operation, 'getPackage') ? $operation->getPackage()->getAutoload() : $operation->getTargetPackage()->getAutoload();
        return $autoload['psr-4'] ?? [];
    }

    /**
     * FindHelper.
     *
     * @return void
     */
    protected static function findHelper(): void
    {
        // Installer.php in T2Engine
        require_once __DIR__ . '/helpers.php';
    }
}
