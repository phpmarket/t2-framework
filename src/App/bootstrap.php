<?php

use App\Env;
use App\Log;
use T2\Bootstrap;
use T2\Config;
use T2\Middleware;
use T2\Route;
use T2\Util;
use Workerman\Events\Select;
use Workerman\Worker;

// 初始化 Worker 的事件循环机制
initializeEventLoop();
// 注册全局错误处理器
try {
    setGlobalErrorHandler();
} catch (ErrorException $e) {
    echo $e;
}
// 注册脚本关闭时的回调
registerShutdownCallback($worker ?? null);
// 加载 .env 环境变量文件
loadEnvironmentVariables(base_path() . DIRECTORY_SEPARATOR . '.env');

Config::clear();
T2\App::loadAllConfig(['route']);
if ($timezone = config('app.default_timezone')) {
    date_default_timezone_set($timezone);
}

foreach (config('autoload.files', []) as $file) {
    include_once $file;
}
foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project)) {
            continue;
        }
        foreach ($project['autoload']['files'] ?? [] as $file) {
            include_once $file;
        }
    }
    foreach ($projects['autoload']['files'] ?? [] as $file) {
        include_once $file;
    }
}

Middleware::load(config('middleware', []));
foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project) || $name === 'static') {
            continue;
        }
        Middleware::load($project['middleware'] ?? []);
    }
    Middleware::load($projects['middleware'] ?? [], $firm);
    if ($staticMiddlewares = config("plugin.$firm.static.middleware")) {
        Middleware::load(['__static__' => $staticMiddlewares], $firm);
    }
}
Middleware::load(['__static__' => config('static.middleware', [])]);

foreach (config('bootstrap', []) as $className) {
    if (!class_exists($className)) {
        $log = "Warning: Class $className setting in config/bootstrap.php not found\r\n";
        echo $log;
        Log::error($log);
        continue;
    }
    /**
     * @var Bootstrap $className
     */
    $className::start($worker);
}

foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project)) {
            continue;
        }
        foreach ($project['bootstrap'] ?? [] as $className) {
            if (!class_exists($className)) {
                $log = "Warning: Class $className setting in config/plugin/$firm/$name/bootstrap.php not found\r\n";
                echo $log;
                Log::error($log);
                continue;
            }
            /**
             * @var Bootstrap $className
             */
            $className::start($worker);
        }
    }
    foreach ($projects['bootstrap'] ?? [] as $className) {
        /**
         * @var string $className
         */
        if (!class_exists($className)) {
            $log = "Warning: Class $className setting in plugin/$firm/config/bootstrap.php not found\r\n";
            echo $log;
            Log::error($log);
            continue;
        }
        /**
         * @var Bootstrap $className
         */
        $className::start($worker);
    }
}

$directory = base_path() . '/plugin';
$paths = [config_path()];
foreach (Util::scanDir($directory) as $path) {
    if (is_dir($path = "$path/config")) {
        $paths[] = $path;
    }
}
Route::load($paths);

/**
 * 初始化 Worker 的事件循环机制
 *
 * @return void
 */
function initializeEventLoop(): void
{
    if (empty(Worker::$eventLoopClass)) {
        Worker::$eventLoopClass = Select::class;
    }
}

/**
 * 注册全局错误处理器
 *
 * @return void
 * @throws ErrorException
 */
function setGlobalErrorHandler(): void
{
    set_error_handler(function ($level, $message, $file = '', $line = 0) {
        if (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    });
}

/**
 * 注册脚本关闭时的回调
 *
 * @param Worker|null $worker
 *
 * @return void
 */
function registerShutdownCallback(?Worker $worker): void
{
    if ($worker) {
        register_shutdown_function(function ($startTime) {
            if (time() - $startTime <= 0.1) {
                sleep(1);
            }
        }, time());
    }
}

/**
 * 加载 .env 环境变量文件
 *
 * @param string $envPath
 *
 * @return void
 */
function loadEnvironmentVariables(string $envPath): void
{
    if (class_exists(Env::class) && file_exists($envPath) && method_exists(Env::class, 'load')) {
        try {
            Env::load($envPath);
        } catch (Throwable $e) {
            error_log("Failed to load .env file: " . $e->getMessage());
        }
    }
}
