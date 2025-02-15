<?php

use App\Log;
use T2\Bootstrap;
use T2\Config;
use T2\Middleware;
use T2\Route;
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
// 清空配置缓存并加载应用配置
Config::clear();
App\Application::loadAllConfig(['route']);
// 设置默认时区
setDefaultTimezone(config('app.default_timezone'));
// 自动加载配置中定义的文件
autoloadFiles(config('autoload.files', []));
// 加载全局中间件
Middleware::load(config('middleware', []));
// 加载全局处理静态文件中间件
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
    $className::start($worker ?? null);;
}
$paths = [config_path()];
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
 * 设置默认时区
 *
 * @param string|null $timezone
 *
 * @return void
 */
function setDefaultTimezone(?string $timezone): void
{
    if ($timezone) {
        date_default_timezone_set($timezone);
    }
}

/**
 * 自动加载指定文件
 *
 * @param array $files
 *
 * @return void
 */
function autoloadFiles(array $files): void
{
    foreach ($files as $file) {
        include_once $file;
    }
}