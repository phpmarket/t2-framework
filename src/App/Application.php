<?php

namespace App;

use RuntimeException;
use T2\App;
use T2\Config;
use T2\Util;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use function base_path;
use function call_user_func;
use function is_dir;
use function opcache_get_status;
use function opcache_invalidate;
use const DIRECTORY_SEPARATOR;

class Application
{
    /**
     * Run.
     *
     * @return void
     * @throws Throwable
     */
    public static function run(): void
    {
        ini_set('display_errors', 'on');
        error_reporting(E_ALL);
        // 加载 .env 环境变量文件
        loadEnvironmentVariables(base_path() . DIRECTORY_SEPARATOR . '.env');
        if (!$appConfigFile = config_path('app.php')) {
            throw new RuntimeException('Config file not found: app.php');
        }
        $appConfig = require $appConfigFile;
        if ($timezone = $appConfig['default_timezone'] ?? '') {
            date_default_timezone_set($timezone);
        }
        static::loadAllConfig(['route', 'container']);
        if (DIRECTORY_SEPARATOR === '\\' && empty(config('server.listen'))) {
            echo "Please run 'php windows.php' on windows system." . PHP_EOL;
            exit;
        }
        $errorReporting = config('app.error_reporting');
        if (isset($errorReporting)) {
            error_reporting($errorReporting);
        }
        $runtimeLogsPath = runtime_path() . DIRECTORY_SEPARATOR . 'logs';
        if (!file_exists($runtimeLogsPath) || !is_dir($runtimeLogsPath)) {
            if (!mkdir($runtimeLogsPath, 0777, true)) {
                throw new RuntimeException("Failed to create runtime logs directory. Please check the permission.");
            }
        }
        $runtimeViewsPath = runtime_path() . DIRECTORY_SEPARATOR . 'views';
        if (!file_exists($runtimeViewsPath) || !is_dir($runtimeViewsPath)) {
            if (!mkdir($runtimeViewsPath, 0777, true)) {
                throw new RuntimeException("Failed to create runtime views directory. Please check the permission.");
            }
        }
        Worker::$onMasterReload = function () {
            if (function_exists('opcache_get_status')) {
                if ($status = opcache_get_status()) {
                    if (isset($status['scripts']) && $scripts = $status['scripts']) {
                        foreach (array_keys($scripts) as $file) {
                            opcache_invalidate($file, true);
                        }
                    }
                }
            }
        };
        $config = config('server');
        Worker::$pidFile = $config['pid_file'];
        Worker::$stdoutFile = $config['stdout_file'];
        Worker::$logFile = $config['log_file'];
        Worker::$eventLoopClass = $config['event_loop'] ?? '';
        TcpConnection::$defaultMaxPackageSize = $config['max_package_size'] ?? 10 * 1024 * 1024;
        if (property_exists(Worker::class, 'statusFile')) {
            Worker::$statusFile = $config['status_file'] ?? '';
        }
        if (property_exists(Worker::class, 'stopTimeout')) {
            Worker::$stopTimeout = $config['stop_timeout'] ?? 2;
        }
        if ($config['listen'] ?? false) {
            $worker = new Worker($config['listen'], $config['context']);
            $propertyMap = [
                'name',
                'count',
                'user',
                'group',
                'reusePort',
                'transport',
                'protocol'
            ];
            foreach ($propertyMap as $property) {
                if (isset($config[$property])) {
                    $worker->$property = $config[$property];
                }
            }
            $worker->onWorkerStart = function ($worker) {
                require_once base_path() . '/vendor/phpmarket/t2-framework/src/App/bootstrap.php';
                $app = new App(config('app.request_class', Request::class), Log::channel(), app_path(), public_path());
                $worker->onMessage = [$app, 'onMessage'];
                call_user_func([$app, 'onWorkerStart'], $worker);
            };
        }
        // Windows does not support custom processes.
        if (DIRECTORY_SEPARATOR === '/') {
            foreach (config('process', []) as $processName => $config) {
                worker_start($processName, $config);
            }
        }
        Worker::runAll();
    }

    /**
     * LoadAllConfig.
     *
     * @param array $excludes
     *
     * @return void
     */
    public static function loadAllConfig(array $excludes = []): void
    {
        Config::load(config_path(), $excludes);
        $directory = base_path() . '/plugin';
        foreach (Util::scanDir($directory, false) as $name) {
            $dir = "$directory/$name/config";
            if (is_dir($dir)) {
                Config::load($dir, $excludes, "plugin.$name");
            }
        }
    }
}