<?php

namespace T2;

class Install
{
    const bool IS_INSTALL = true;

    /**
     * @var array $pathRelation
     * 路径映射关系
     * 键：源文件路径（相对于当前目录）
     * 值：目标文件路径（相对于项目根目录）
     * 作用：用于在安装时，将源文件复制到目标位置
     */
    protected static array $pathRelation = [
        'start.php'         => 'start.php', // 根目录下的 start.php
        'windows.php'       => 'windows.php', // 根目录下的 windows.php
        'windows.bat'       => 'windows.bat', // 根目录下的 windows.bat
        'App/functions.php' => 'app/functions.php', // App 目录下的 functions.php
        'App/Request.php'   => 'app/Request.php', // App 目录下的 Request.php
        'App/Response.php'  => 'app/Response.php', // App 目录下的 Response.php
    ];

    /**
     * 安装方法
     *
     * 功能：执行插件的安装操作
     * 步骤：
     * 1. 根据路径关系进行文件复制
     * 2. 移除 Bootstrap 中的 LaravelDb 配置
     *
     * @return void
     */
    public static function install(): void
    {
        // 调用 installByRelation 方法，按照路径关系复制文件
        static::installByRelation();

        // 调用 removeLaravelDbFromBootstrap 方法，从 bootstrap 配置中移除 LaravelDb
        static::removeLaravelDbFromBootstrap();
    }

    /**
     * 卸载方法
     *
     * 功能：执行插件的卸载操作
     * 当前方法为空，可根据需求进行扩展，例如删除已安装的文件或配置
     *
     * @return void
     */
    public static function uninstall()
    {
        // 当前未定义任何卸载逻辑
        // 可根据需求添加删除已安装文件或清理配置的操作
    }

    /**
     * 根据路径关系进行文件复制
     *
     * 功能：按照 $pathRelation 中定义的路径关系，
     *       将源文件复制到目标目录。若目标文件已存在，则跳过复制；
     *       若不存在，则进行复制，并在复制后删除源文件。
     *
     * 步骤：
     * 1. 遍历 $pathRelation 数组，获取每一对路径关系
     * 2. 检查目标目录是否存在，若不存在则递归创建
     * 3. 检查目标文件是否已存在
     *      - 如果已存在，则跳过该文件的复制
     *      - 如果不存在，则进行文件或目录的复制
     * 4. 输出已创建的目标路径
     * 5. 如果源文件为普通文件，则删除该文件
     * 6. 检查并清空 helpers.php 文件内容
     *
     * @return void
     */
    /**
     * installByRelation
     *
     * @return void
     */
    public static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            $parentDir = base_path(dirname($dest));
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0777, true);
            }
            $destFile = base_path($dest);
            $sourceFile = __DIR__ . "/$source";
            // 如果目标文件已存在，跳过复制，但仍尝试删除源文件
            if (!file_exists($destFile)) {
                // 复制目录或文件到目标路径（递归复制）
                copy_dir($sourceFile, $destFile, true);
                echo "Create $dest\r\n";
            }
            if (is_file($sourceFile) && is_writable($sourceFile)) {
                @unlink($sourceFile);
            } elseif (is_dir($sourceFile)) {
                self::recursiveRemoveDir($sourceFile);
            }
        }
    }

    /**
     * 递归删除目录及其内容
     *
     * @param string $dir 目录路径
     *
     * @return void
     */
    private static function recursiveRemoveDir(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                self::recursiveRemoveDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * 移除 Bootstrap 配置中的 LaravelDb
     *
     * 功能：从 config/bootstrap.php 中移除 LaravelDb 的配置项
     *
     * 步骤：
     * 1. 检查 bootstrap.php 文件是否存在
     * 2. 定义正则表达式，匹配 LaravelDb 配置项
     * 3. 读取 bootstrap.php 文件内容
     * 4. 使用正则表达式检查是否存在匹配内容
     * 5. 如果存在匹配内容，则进行替换，并写入更新后的内容
     *
     * 正则表达式解释：
     * - `^\s*`：匹配行首的空白字符
     * - `App\\\\bootstrap\\\\LaravelDb::class`：匹配 LaravelDb 配置项
     * - `,?\s*?`：匹配逗号和后续空白字符（可选）
     * - `\r?\n`：匹配换行符（兼容 Windows 和 Unix）
     *
     * @return void
     */
    protected static function removeLaravelDbFromBootstrap(): void
    {
        // 定义 bootstrap.php 文件路径
        $file = base_path('config/bootstrap.php');

        // 检查文件是否存在，如果不存在则直接返回
        if (!is_file($file)) {
            return;
        }

        // 定义正则表达式，用于匹配 LaravelDb 配置项
        $pattern = '/^\s*support\\\\bootstrap\\\\LaravelDb::class,?\s*?\r?\n/m';

        // 读取 bootstrap.php 文件内容
        $content = file_get_contents($file);

        // 检查内容中是否存在匹配项
        if (preg_match($pattern, $content)) {
            // 如果存在，则进行替换，并去除匹配的行
            $updatedContent = preg_replace($pattern, '', $content);

            // 将更新后的内容写回文件
            file_put_contents($file, $updatedContent);
        }
    }

}