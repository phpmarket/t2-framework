<?php

namespace T2;

use ArrayObject;
use Closure;
use Exception;
use FastRoute\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use ReflectionEnum;
use App\Exception\InputValueException;
use App\Exception\PageNotFoundException;
use Monolog\Logger;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use App\Exception\MissingInputException;
use App\Exception\InputTypeException;
use Throwable;
use T2\Exception\ExceptionHandler;
use T2\Exception\ExceptionHandlerInterface;
use T2\Http\Request;
use T2\Http\Response;
use T2\Route\Route as RouteObject;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Worker;
use function array_merge;
use function array_pop;
use function array_reduce;
use function array_splice;
use function array_values;
use function class_exists;
use function clearstatcache;
use function count;
use function current;
use function end;
use function explode;
use function get_class_methods;
use function gettype;
use function implode;
use function is_a;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function key;
use function str_contains;
use function str_ends_with;
use function method_exists;
use function ob_get_clean;
use function ob_start;
use function pathinfo;
use function scandir;
use function str_replace;
use function strtolower;
use function substr;
use function trim;
use function call_user_func;

class App
{
    /**
     * @var callable[]
     */
    protected static $callbacks = [];

    /**
     * @var Worker
     */
    protected static $worker = null;

    /**
     * @var Logger
     */
    protected static $logger = null;

    /**
     * @var string
     */
    protected static $appPath = '';

    /**
     * @var string
     */
    protected static $publicPath = '';

    /**
     * @var string
     */
    protected static $requestClass = '';

    /**
     * App constructor.
     *
     * @param string $requestClass
     * @param Logger $logger
     * @param string $appPath
     * @param string $publicPath
     */
    public function __construct(string $requestClass, Logger $logger, string $appPath, string $publicPath)
    {
        static::$requestClass = $requestClass;
        static::$logger = $logger;
        static::$publicPath = $publicPath;
        static::$appPath = $appPath;
    }

    /**
     * OnMessage.
     *
     * @param TcpConnection|mixed $connection
     * @param Request|mixed       $request
     *
     * @return null
     * @throws Throwable
     */
    public function onMessage($connection, $request)
    {
        try {
            Context::reset(new ArrayObject([Request::class => $request]));
            $path = $request->path();
            $key = $request->method() . $path;
            if (isset(static::$callbacks[$key])) {
                [$callback, $request->web, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
                static::send($connection, $callback($request), $request);
                return null;
            }

            $status = 200;
            if (
                static::unsafeUri($connection, $path, $request) ||
                static::findFile($connection, $path, $key, $request) ||
                static::findRoute($connection, $path, $key, $request, $status)
            ) {
                return null;
            }

            $controllerAndAction = static::parseControllerAction($path);
            $web = $controllerAndAction['web'] ?? static::getWebByPath($path);
            if (!$controllerAndAction || Route::isDefaultRouteDisabled($web, $controllerAndAction['app'] ?: '*') ||
                Route::isDefaultRouteDisabled($controllerAndAction['controller']) ||
                Route::isDefaultRouteDisabled([$controllerAndAction['controller'], $controllerAndAction['action']])) {
                $request->web = $web;
                $callback = static::getFallback($web, $status);
                $request->app = $request->controller = $request->action = '';
                static::send($connection, $callback($request), $request);
                return null;
            }
            $app = $controllerAndAction['app'];
            $controller = $controllerAndAction['controller'];
            $action = $controllerAndAction['action'];
            $callback = static::getCallback($web, $app, [$controller, $action]);
            static::collectCallbacks($key, [$callback, $web, $app, $controller, $action, null]);
            [$callback, $request->web, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
            static::send($connection, $callback($request), $request);
        } catch (Throwable $e) {
            static::send($connection, static::exceptionResponse($e, $request), $request);
        }
        return null;
    }

    /**
     * OnWorkerStart.
     *
     * @param $worker
     *
     * @return void
     */
    public function onWorkerStart($worker): void
    {
        static::$worker = $worker;
        Http::requestClass(static::$requestClass);
    }

    /**
     * CollectCallbacks.
     *
     * @param string $key
     * @param array  $data
     *
     * @return void
     */
    protected static function collectCallbacks(string $key, array $data): void
    {
        static::$callbacks[$key] = $data;
        if (count(static::$callbacks) >= 1024) {
            unset(static::$callbacks[key(static::$callbacks)]);
        }
    }

    /**
     * UnsafeUri
     *
     * @param TcpConnection $connection
     * @param string        $path
     * @param               $request
     *
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected static function unsafeUri(TcpConnection $connection, string $path, $request): bool
    {
        if (!$path || $path[0] !== '/' || str_contains($path, '/../') || str_ends_with($path, '/..') || str_contains($path, "\\") || str_contains($path, "\0")) {
            $callback = static::getFallback('', 400);
            $request->plugin = $request->app = $request->controller = $request->action = '';
            static::send($connection, $callback($request, 400), $request);
            return true;
        }
        return false;
    }

    /**
     * GetFallback.
     *
     * @param string $plugin
     * @param int    $status
     *
     * @return Closure
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected static function getFallback(string $plugin = '', int $status = 404): Closure
    {
        // When route, controller and action not found, try to use Route::fallback
        return Route::getFallback($plugin, $status) ?: function () {
            throw new PageNotFoundException();
        };
    }

    /**
     * ExceptionResponse.
     *
     * @param Throwable $e
     * @param           $request
     *
     * @return Response
     */
    protected static function exceptionResponse(Throwable $e, $request): Response
    {
        try {
            $app = $request->app ?: '';
            $plugin = $request->plugin ?: '';
            $exceptionConfig = static::config($plugin, 'exception');
            $appExceptionConfig = static::config("", 'exception');
            if (!isset($exceptionConfig['']) && isset($appExceptionConfig['@'])) {
                //如果插件没有配置自己的异常处理器并且配置了全局@异常处理器 则使用全局异常处理器
                $defaultException = $appExceptionConfig['@'] ?? ExceptionHandler::class;
            } else {
                $defaultException = $exceptionConfig[''] ?? ExceptionHandler::class;
            }
            $exceptionHandlerClass = $exceptionConfig[$app] ?? $defaultException;

            /**
             * @var ExceptionHandlerInterface $exceptionHandler
             */
            $exceptionHandler = (static::container($plugin) ?? static::container(''))->make($exceptionHandlerClass, [
                'logger' => static::$logger,
                'debug'  => static::config($plugin, 'app.debug')
            ]);
            $exceptionHandler->report($e);
            $response = $exceptionHandler->render($request, $e);
            $response->exception($e);
            return $response;
        } catch (Throwable $e) {
            $response = new Response(500, [], static::config($plugin ?? '', 'app.debug', true) ? (string)$e : $e->getMessage());
            $response->exception($e);
            return $response;
        }
    }

    /**
     * GetCallback.
     *
     * @param string           $plugin
     * @param string           $app
     * @param                  $call
     * @param array            $args
     * @param bool             $withGlobalMiddleware
     * @param RouteObject|null $route
     *
     * @return callable
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public static function getCallback(string $plugin, string $app, $call, array $args = [], bool $withGlobalMiddleware = true, ?RouteObject $route = null)
    {
        $isController = is_array($call) && is_string($call[0]);
        $middlewares = Middleware::getMiddleware($plugin, $app, $call, $route, $withGlobalMiddleware);

        $container = static::container($plugin) ?? static::container('');
        foreach ($middlewares as $key => $item) {
            $middleware = $item[0];
            if (is_string($middleware)) {
                $middleware = $container->get($middleware);
            } elseif ($middleware instanceof Closure) {
                $middleware = call_user_func($middleware, $container);
            }
            $middlewares[$key][0] = $middleware;
        }

        $needInject = static::isNeedInject($call, $args);
        $anonymousArgs = array_values($args);
        if ($isController) {
            $controllerReuse = static::config($plugin, 'app.controller_reuse', true);
            if (!$controllerReuse) {
                if ($needInject) {
                    $call = function ($request) use ($call, $plugin, $args, $container) {
                        $call[0] = $container->make($call[0]);
                        $reflector = static::getReflector($call);
                        $args = array_values(static::resolveMethodDependencies($container, $request, array_merge($request->all(), $args), $reflector, static::config($plugin, 'app.debug')));
                        return $call(...$args);
                    };
                    $needInject = false;
                } else {
                    $call = function ($request, ...$anonymousArgs) use ($call, $plugin, $container) {
                        $call[0] = $container->make($call[0]);
                        return $call($request, ...$anonymousArgs);
                    };
                }
            } else {
                $call[0] = $container->get($call[0]);
            }
        }

        if ($needInject) {
            $call = static::resolveInject($plugin, $call, $args);
        }

        if ($middlewares) {
            $callback = array_reduce($middlewares, function ($carry, $pipe) {
                return function ($request) use ($carry, $pipe) {
                    try {
                        return $pipe($request, $carry);
                    } catch (Throwable $e) {
                        return static::exceptionResponse($e, $request);
                    }
                };
            }, function ($request) use ($call, $anonymousArgs) {
                try {
                    $response = $call($request, ...$anonymousArgs);
                } catch (Throwable $e) {
                    return static::exceptionResponse($e, $request);
                }
                if (!$response instanceof Response) {
                    if (!is_string($response)) {
                        $response = static::stringify($response);
                    }
                    $response = new Response(200, [], $response);
                }
                return $response;
            });
        } else {
            if (!$anonymousArgs) {
                $callback = $call;
            } else {
                $callback = function ($request) use ($call, $anonymousArgs) {
                    return $call($request, ...$anonymousArgs);
                };
            }
        }
        return $callback;
    }

    /**
     * ResolveInject.
     *
     * @param string        $plugin
     * @param array|Closure $call
     * @param               $args
     *
     * @return Closure
     * @see Dependency injection through reflection information
     */
    protected static function resolveInject(string $plugin, $call, $args): Closure
    {
        return function (Request $request) use ($plugin, $call, $args) {
            $reflector = static::getReflector($call);
            $args = array_values(static::resolveMethodDependencies(static::container($plugin), $request, array_merge($request->all(), $args), $reflector, static::config($plugin, 'app.debug')));
            return $call(...$args);
        };
    }

    /**
     * Check whether inject is required.
     *
     * @param       $call
     * @param array $args
     *
     * @return bool
     * @throws ReflectionException
     */
    protected static function isNeedInject($call, array &$args): bool
    {
        if (is_array($call) && !method_exists($call[0], $call[1])) {
            return false;
        }
        $reflector = static::getReflector($call);
        $reflectionParameters = $reflector->getParameters();
        if (!$reflectionParameters) {
            return false;
        }
        $firstParameter = current($reflectionParameters);
        unset($reflectionParameters[key($reflectionParameters)]);
        $adaptersList = ['int', 'string', 'bool', 'array', 'object', 'float', 'mixed', 'resource'];
        $keys = [];
        $needInject = false;
        foreach ($reflectionParameters as $parameter) {
            $parameterName = $parameter->name;
            $keys[] = $parameterName;
            if ($parameter->hasType()) {
                $typeName = $parameter->getType()->getName();
                if (!in_array($typeName, $adaptersList)) {
                    $needInject = true;
                    continue;
                }
                if (!array_key_exists($parameterName, $args)) {
                    $needInject = true;
                    continue;
                }
                switch ($typeName) {
                    case 'int':
                    case 'float':
                        if (!is_numeric($args[$parameterName])) {
                            return true;
                        }
                        $args[$parameterName] = $typeName === 'int' ? (int)$args[$parameterName] : (float)$args[$parameterName];
                        break;
                    case 'bool':
                        $args[$parameterName] = (bool)$args[$parameterName];
                        break;
                    case 'array':
                    case 'object':
                        if (!is_array($args[$parameterName])) {
                            return true;
                        }
                        $args[$parameterName] = $typeName === 'array' ? $args[$parameterName] : (object)$args[$parameterName];
                        break;
                    case 'string':
                    case 'mixed':
                    case 'resource':
                        break;
                }
            }
        }
        if (array_keys($args) !== $keys) {
            return true;
        }
        if (!$firstParameter->hasType()) {
            return $firstParameter->getName() !== 'request';
        }
        if (!is_a(static::$requestClass, $firstParameter->getType()->getName(), true)) {
            return true;
        }

        return $needInject;
    }

    /**
     * Get reflector.
     *
     * @param $call
     *
     * @return ReflectionFunction|ReflectionMethod
     * @throws ReflectionException
     */
    protected static function getReflector($call)
    {
        if ($call instanceof Closure || is_string($call)) {
            return new ReflectionFunction($call);
        }
        return new ReflectionMethod($call[0], $call[1]);
    }

    /**
     * Return dependent parameters
     *
     * @param ContainerInterface         $container
     * @param Request                    $request
     * @param array                      $inputs
     * @param ReflectionFunctionAbstract $reflector
     * @param bool                       $debug
     *
     * @return array
     * @throws ReflectionException
     */
    protected static function resolveMethodDependencies(ContainerInterface $container, Request $request, array $inputs, ReflectionFunctionAbstract $reflector, bool $debug): array
    {
        $parameters = [];
        foreach ($reflector->getParameters() as $parameter) {
            $parameterName = $parameter->name;
            $type = $parameter->getType();
            $typeName = $type?->getName();

            if ($typeName && is_a($request, $typeName)) {
                $parameters[$parameterName] = $request;
                continue;
            }

            if (!array_key_exists($parameterName, $inputs)) {
                if (!$parameter->isDefaultValueAvailable()) {
                    if (!$typeName || (!class_exists($typeName) && !enum_exists($typeName)) || enum_exists($typeName)) {
                        throw (new MissingInputException())->data([
                            'parameter' => $parameterName,
                        ])->debug($debug);
                    }
                } else {
                    $parameters[$parameterName] = $parameter->getDefaultValue();
                    continue;
                }
            }

            $parameterValue = $inputs[$parameterName] ?? null;

            switch ($typeName) {
                case 'int':
                case 'float':
                    if (!is_numeric($parameterValue)) {
                        throw (new InputTypeException())->data([
                            'parameter'  => $parameterName,
                            'exceptType' => $typeName,
                            'actualType' => gettype($parameterValue),
                        ])->debug($debug);
                    }
                    $parameters[$parameterName] = $typeName === 'float' ? (float)$parameterValue : (int)$parameterValue;
                    break;
                case 'bool':
                    $parameters[$parameterName] = (bool)$parameterValue;
                    break;
                case 'array':
                case 'object':
                    if (!is_array($parameterValue)) {
                        throw (new InputTypeException())->data([
                            'parameter'  => $parameterName,
                            'exceptType' => $typeName,
                            'actualType' => gettype($parameterValue),
                        ])->debug($debug);
                    }
                    $parameters[$parameterName] = $typeName === 'object' ? (object)$parameterValue : $parameterValue;
                    break;
                case 'string':
                case 'mixed':
                case 'resource':
                case null:
                    $parameters[$parameterName] = $parameterValue;
                    break;
                default:
                    $subInputs = is_array($parameterValue) ? $parameterValue : [];
                    if (is_a($typeName, Model::class, true)) {
                        $parameters[$parameterName] = $container->make($typeName, [
                            'attributes' => $subInputs,
                            'data'       => $subInputs
                        ]);
                        break;
                    }
                    if (enum_exists($typeName)) {
                        $reflection = new ReflectionEnum($typeName);
                        if ($reflection->hasCase($parameterValue)) {
                            $parameters[$parameterName] = $reflection->getCase($parameterValue)->getValue();
                            break;
                        } elseif ($reflection->isBacked()) {
                            foreach ($reflection->getCases() as $case) {
                                if ($case->getValue()->value == $parameterValue) {
                                    $parameters[$parameterName] = $case->getValue();
                                    break;
                                }
                            }
                        }
                        if (!array_key_exists($parameterName, $parameters)) {
                            throw (new InputValueException())->data([
                                'parameter' => $parameterName,
                                'enum'      => $typeName
                            ])->debug($debug);
                        }
                        break;
                    }
                    if (is_array($subInputs) && $constructor = (new ReflectionClass($typeName))->getConstructor()) {
                        $parameters[$parameterName] = $container->make($typeName, static::resolveMethodDependencies($container, $request, $subInputs, $constructor, $debug));
                    } else {
                        $parameters[$parameterName] = $container->make($typeName);
                    }
                    break;
            }
        }
        return $parameters;
    }

    /**
     * Container.
     *
     * @param string $plugin
     *
     * @return ContainerInterface
     */
    public static function container(string $plugin = '')
    {
        return static::config($plugin, 'container');
    }

    /**
     * Get request.
     *
     * @return Request|\App\Request
     */
    public static function request()
    {
        return Context::get(Request::class);
    }

    /**
     * Get worker.
     *
     * @return ?Worker
     */
    public static function worker(): ?Worker
    {
        return static::$worker;
    }

    /**
     * Find Route.
     *
     * @param TcpConnection $connection
     * @param string        $path
     * @param string        $key
     * @param               $request
     * @param               $status
     *
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException|Throwable
     */
    protected static function findRoute(TcpConnection $connection, string $path, string $key, $request, &$status): bool
    {
        $routeInfo = Route::dispatch($request->method(), $path);
        if ($routeInfo[0] === Dispatcher::FOUND) {
            $status = 200;
            $routeInfo[0] = 'route';
            $callback = $routeInfo[1]['callback'];
            $route = clone $routeInfo[1]['route'];
            $app = $controller = $action = '';
            $args = !empty($routeInfo[2]) ? $routeInfo[2] : [];
            if ($args) {
                $route->setParams($args);
            }
            if (is_array($callback)) {
                $controller = $callback[0];
                $web = static::getWebByClass($controller);
                $app = static::getAppByController($controller);
                $action = static::getRealMethod($controller, $callback[1]) ?? '';
            } else {
                $web = static::getWebByPath($path);
            }
            $callback = static::getCallback($web, $app, $callback, $args, true, $route);
            static::collectCallbacks($key, [$callback, $web, $app, $controller ?: '', $action, $route]);
            [$callback, $request->web, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
            static::send($connection, $callback($request), $request);
            return true;
        }
        $status = $routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED ? 405 : 404;
        return false;
    }

    /**
     * Find File.
     *
     * @param TcpConnection $connection
     * @param string        $path
     * @param string        $key
     * @param               $request
     *
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected static function findFile(TcpConnection $connection, string $path, string $key, $request): bool
    {
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if (static::unsafeUri($connection, $path, $request)) {
                return true;
            }
        }

        $pathExplodes = explode('/', trim($path, '/'));
        $plugin = '';
        if (isset($pathExplodes[1]) && $pathExplodes[0] === 'app') {
            $plugin = $pathExplodes[1];
            $publicDir = static::config($plugin, 'app.public_path') ?: BASE_PATH . "/plugin/$pathExplodes[1]/public";
            $path = substr($path, strlen("/app/$pathExplodes[1]/"));
        } else {
            $publicDir = static::$publicPath;
        }
        $file = "$publicDir/$path";
        if (!is_file($file)) {
            return false;
        }

        if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            if (!static::config($plugin, 'app.support_php_files', false)) {
                return false;
            }
            static::collectCallbacks($key, [function () use ($file) {
                return static::execPhpFile($file);
            }, '', '', '', '', null]);
            [, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
            static::send($connection, static::execPhpFile($file), $request);
            return true;
        }

        if (!static::config($plugin, 'static.enable', false)) {
            return false;
        }

        static::collectCallbacks($key, [static::getCallback($plugin, '__static__', function ($request) use ($file, $plugin) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                $callback = static::getFallback($plugin);
                return $callback($request);
            }
            return (new Response())->file($file);
        }, [], false), '', '', '', '', null]);
        [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
        static::send($connection, $callback($request), $request);
        return true;
    }

    /**
     * Send.
     *
     * @param TcpConnection|mixed $connection
     * @param mixed|Response      $response
     * @param Request|mixed       $request
     *
     * @return void
     */
    protected static function send($connection, $response, $request)
    {
        Context::destroy();
        $keepAlive = $request->header('connection');
        if (($keepAlive === null && $request->protocolVersion() === '1.1') || $keepAlive === 'keep-alive' || $keepAlive === 'Keep-Alive' || (is_a($response, Response::class) && $response->getHeader('Transfer-Encoding') === 'chunked')) {
            $connection->send($response);
            return;
        }
        $connection->close($response);
    }

    /**
     * 解析控制器和操作方法
     *
     * @param string $path 请求的 URL 路径
     *
     * @return array|false|mixed 返回解析后的控制器和方法，或 false 表示解析失败
     * @throws ReflectionException
     */
    protected static function parseControllerAction(string $path): mixed
    {
        // 替换路径中的连字符和多余的斜杠
        $path = str_replace(['-', '//'], ['', '/'], $path);
        // 静态缓存，用于提高性能
        static $cache = [];
        if (isset($cache[$path])) {
            // 如果存在该路径的解析结果，则直接返回
            return $cache[$path];
        }
        // 将路径按斜杠分割为数组
        $pathExplode = explode('/', trim($path, '/'));
        // 判断是否是 t2-website 应用
        $isWebsite = isset($pathExplode[1]) && $pathExplode[0] === 'web';
        // 配置前缀，例如：web.
        $configPrefix = $isWebsite ? "$pathExplode[0]." : '';
        // 路径前缀，例如：/web
        $pathPrefix = $isWebsite ? "/web" : "/app/$pathExplode[1]";
        // 类名前缀，例如：web
        $classPrefix = $isWebsite ? "web" : "app\\$pathExplode[1]";
        // 获取控制器后缀，例如：Controller
        $suffix = Config::get("{$configPrefix}controller_suffix", '');
        // 获取相对路径（去掉前缀部分）
        $relativePath = trim(substr($path, strlen($pathPrefix)), '/');
        // 重新分割路径
        $pathExplode = $relativePath ? explode('/', $relativePath) : [];
        // 默认方法名为 index
        $action = 'index';
        // 尝试猜测控制器和方法
        if (!$controllerAction = static::guessControllerAction($pathExplode, $action, $suffix, $classPrefix)) {
            // 如果第一次猜测失败，并且路径部分不超过 1 个，则返回 false
            if (count($pathExplode) <= 1) {
                return false;
            }
            // 如果路径部分超过 1 个，则将最后一个部分作为方法名
            $action = end($pathExplode);
            // 移除最后一个部分
            unset($pathExplode[count($pathExplode) - 1]);
            // 再次猜测控制器和方法
            $controllerAction = static::guessControllerAction($pathExplode, $action, $suffix, $classPrefix);
        }
        // 如果成功解析，并且路径长度不超过 256 字符，则进行缓存
        if ($controllerAction && !isset($path[256])) {
            $cache[$path] = $controllerAction;
            // 缓存超过 1024 项时，移除最早的一项
            if (count($cache) > 1024) {
                unset($cache[key($cache)]);
            }
        }
        // 返回解析结果
        return $controllerAction;
    }

    /**
     * 猜测控制器和操作方法
     *
     * @param array  $pathExplode 路径分割后的数组
     * @param string $action      方法名（默认 index）
     * @param string $suffix      控制器类名后缀（例如：Controller）
     * @param string $classPrefix 类名前缀
     *
     * @return array|false 返回解析后的控制器类名和方法，若无法解析则返回 false
     * @throws ReflectionException
     */
    protected static function guessControllerAction($pathExplode, $action, $suffix, $classPrefix): false|array
    {
        // 构建类名映射数组
        $map = [];
        // 第一种情况：直接拼接路径，例如 app\controller\demo
        $map[] = trim("$classPrefix\\controller\\" . implode('\\', $pathExplode), '\\');
        // 遍历路径部分，尝试在每一部分后面加上 'controller'
        // 例如：app\demo\controller\index 或 app\demo\controller
        foreach ($pathExplode as $index => $section) {
            // 临时数组，复制当前路径部分
            $tmp = $pathExplode;
            // 在当前部分后面加上 'controller'
            array_splice($tmp, $index, 1, [$section, 'controller']);
            // 拼接完整的类名，并添加到映射数组中
            $map[] = trim("$classPrefix\\" . implode('\\', array_merge(['app'], $tmp)), '\\');
        }
        // 为每种类名再尝试加上 'index' 作为默认控制器
        foreach ($map as $item) {
            $map[] = $item . '\\index';
        }
        // 遍历所有可能的类名
        foreach ($map as $controllerClass) {
            // 如果类名以 \controller 结尾，则跳过
            // 这是为了防止重复，例如：app\demo\controller\controller
            if (str_ends_with($controllerClass, '\\controller')) {
                continue;
            }
            // 在类名后面加上控制器后缀（例如：Controller）
            $controllerClass .= $suffix;
            // 尝试获取控制器类和对应的方法
            if ($controllerAction = static::getControllerAction($controllerClass, $action)) {
                // 如果找到则返回数组形式 [类名, 方法名]
                return $controllerAction;
            }
        }
        // 如果都未找到，则返回 false
        return false;
    }

    /**
     * GetControllerAction.
     *
     * @param string $controllerClass
     * @param string $action
     *
     * @return array|false
     * @throws ReflectionException
     */
    protected static function getControllerAction(string $controllerClass, string $action): false|array
    {
        // Disable calling magic methods
        if (str_starts_with($action, '__')) {
            return false;
        }
        if (($controllerClass = static::getController($controllerClass)) && ($action = static::getAction($controllerClass, $action))) {
            return [
                'web'        => static::getWebByClass($controllerClass),
                'app'        => static::getAppByController($controllerClass),
                'controller' => $controllerClass,
                'action'     => $action
            ];
        }
        return false;
    }

    /**
     * GetController.
     *
     * @param string $controllerClass
     *
     * @return string|false
     * @throws ReflectionException
     */
    protected static function getController(string $controllerClass): false|string
    {
        if (class_exists($controllerClass)) {
            return (new ReflectionClass($controllerClass))->name;
        }
        $explodes = explode('\\', strtolower(ltrim($controllerClass, '\\')));
        $basePath = $explodes[0] === 'web' ? BASE_PATH . '/web' : static::$appPath;
        unset($explodes[0]);
        $fileName = array_pop($explodes) . '.php';
        $found = true;
        foreach ($explodes as $pathSection) {
            if (!$found) {
                break;
            }
            $dirs = Util::scanDir($basePath, false);
            $found = false;
            foreach ($dirs as $name) {
                $path = "$basePath/$name";

                if (is_dir($path) && strtolower($name) === $pathSection) {
                    $basePath = $path;
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            return false;
        }
        foreach (scandir($basePath) ?: [] as $name) {
            if (strtolower($name) === $fileName) {
                require_once "$basePath/$name";
                if (class_exists($controllerClass, false)) {
                    return (new ReflectionClass($controllerClass))->name;
                }
            }
        }
        return false;
    }

    /**
     * GetAction.
     *
     * @param string $controllerClass
     * @param string $action
     *
     * @return string|false
     */
    protected static function getAction(string $controllerClass, string $action)
    {
        $methods = get_class_methods($controllerClass);
        $lowerAction = strtolower($action);
        $found = false;
        foreach ($methods as $candidate) {
            if (strtolower($candidate) === $lowerAction) {
                $action = $candidate;
                $found = true;
                break;
            }
        }
        if ($found) {
            return $action;
        }
        // Action is not public method
        if (method_exists($controllerClass, $action)) {
            return false;
        }
        if (method_exists($controllerClass, '__call')) {
            return $action;
        }
        return false;
    }

    /**
     * getWebByClass
     *
     * @param string $controllerClass
     *
     * @return string
     */
    public static function getWebByClass(string $controllerClass): string
    {
        $controllerClass = trim($controllerClass, '\\');
        $tmp = explode('\\', $controllerClass, 3);
        if ($tmp[0] !== 'web') {
            return '';
        }
        return $tmp[0] ?? '';
    }

    /**
     * getWebByPath
     *
     * @param string $path
     *
     * @return string
     */
    public static function getWebByPath(string $path): string
    {
        $path = trim($path, '/');
        $tmp = explode('/', $path, 3);
        if ($tmp[0] !== 'web') {
            return '';
        }
        $web = $tmp[0] ?? '';
        if ($web && !static::config('', "$web")) {
            return '';
        }
        return $web;
    }

    /**
     * GetAppByController
     *
     * @param string $controllerClass
     *
     * @return mixed|string
     */
    protected static function getAppByController(string $controllerClass): mixed
    {
        $controllerClass = trim($controllerClass, '\\');
        $tmp = explode('\\', $controllerClass, 5);
        if (!isset($tmp[1])) {
            return '';
        }
        return strtolower($tmp[1]) === 'controller' ? '' : $tmp[1];
    }

    /**
     * ExecPhpFile
     *
     * @param string $file
     *
     * @return false|string
     */
    public static function execPhpFile(string $file): false|string
    {
        ob_start();
        // Try to include php file.
        try {
            include $file;
        } catch (Exception $e) {
            echo $e;
        }
        return ob_get_clean();
    }

    /**
     * GetRealMethod.
     *
     * @param string $class
     * @param string $method
     *
     * @return string
     */
    protected static function getRealMethod(string $class, string $method): string
    {
        $method = strtolower($method);
        $methods = get_class_methods($class);
        foreach ($methods as $candidate) {
            if (strtolower($candidate) === $method) {
                return $candidate;
            }
        }
        return $method;
    }

    /**
     * Config
     *
     * @param string     $plugin
     * @param string     $key
     * @param mixed|null $default
     *
     * @return array|mixed
     */
    protected static function config(string $plugin, string $key, mixed $default = null): mixed
    {
        return Config::get($plugin ? "plugin.$plugin.$key" : $key, $default);
    }


    /**
     * @param mixed $data
     *
     * @return string
     */
    protected static function stringify(mixed $data): string
    {
        $type = gettype($data);
        switch ($type) {
            case 'boolean':
                return $data ? 'true' : 'false';
            case 'NULL':
                return 'NULL';
            case 'array':
                return 'Array';
            case 'object':
                if (!method_exists($data, '__toString')) {
                    return 'Object';
                }
            default:
                return (string)$data;
        }
    }
}
