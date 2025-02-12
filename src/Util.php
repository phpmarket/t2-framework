<?php

namespace T2;

use function array_diff;
use function array_map;
use function scandir;

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

}
