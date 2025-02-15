<?php

namespace T2;

use function is_dir;
use function array_diff;
use function array_map;
use function scandir;
use function ucfirst;
use function preg_replace_callback;
use function strtoupper;
use function str_replace;
use function lcfirst;
use function strtolower;
use function strrpos;
use function substr;
use function explode;
use function trim;
use function implode;
use function get_realpath;

class Util
{
    /**
     * ScanDir.
     *
     * @param string $basePath
     * @param bool   $withBasePath
     *
     * @return array
     */
    public static function scanDir(string $basePath, bool $withBasePath = true): array
    {
        if (!is_dir($basePath)) {
            return [];
        }
        $paths = array_diff(scandir($basePath), array('.', '..')) ?: [];
        return $withBasePath ? array_map(static function ($path) use ($basePath) {
            return $basePath . DIRECTORY_SEPARATOR . $path;
        }, $paths) : $paths;
    }

    /**
     * @param $name
     *
     * @return array|string|string[]
     */
    public static function nameToNamespace($name): array|string
    {
        $namespace = ucfirst($name);
        $namespace = preg_replace_callback(['/-([a-zA-Z])/', '/(\/[a-zA-Z])/'], function ($matches) {
            return strtoupper($matches[1]);
        }, $namespace);
        return str_replace('/', '\\', ucfirst($namespace));
    }

    /**
     * @param $class
     *
     * @return array|string|string[]|null
     */
    public static function classToName($class): array|string|null
    {
        $class = lcfirst($class);
        return preg_replace_callback(['/([A-Z])/'], function ($matches) {
            return '_' . strtolower($matches[1]);
        }, $class);
    }

    /**
     * @param $class
     *
     * @return string
     */
    public static function nameToClass($class): string
    {
        $class = preg_replace_callback(['/-([a-zA-Z])/', '/_([a-zA-Z])/'], function ($matches) {
            return strtoupper($matches[1]);
        }, $class);

        if (!($pos = strrpos($class, '/'))) {
            $class = ucfirst($class);
        } else {
            $path = substr($class, 0, $pos);
            $class = ucfirst(substr($class, $pos + 1));
            $class = "$path/$class";
        }
        return $class;
    }

    /**
     * @param      $base_path
     * @param      $name
     * @param bool $return_full_path
     *
     * @return false|string
     */
    public static function guessPath($base_path, $name, bool $return_full_path = false): false|string
    {
        if (!is_dir($base_path)) {
            return false;
        }
        $names = explode('/', trim(strtolower($name), '/'));
        $realName = [];
        $path = $base_path;
        foreach ($names as $name) {
            $finded = false;
            foreach (scandir($path) ?: [] as $tmp_name) {
                if (strtolower($tmp_name) === $name && is_dir("$path/$tmp_name")) {
                    $path = "$path/$tmp_name";
                    $realName[] = $tmp_name;
                    $finded = true;
                    break;
                }
            }
            if (!$finded) {
                return false;
            }
        }
        $realName = implode(DIRECTORY_SEPARATOR, $realName);
        return $return_full_path ? get_realpath($base_path . DIRECTORY_SEPARATOR . $realName) : $realName;
    }
}
