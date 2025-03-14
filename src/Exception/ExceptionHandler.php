<?php

namespace T2\Exception;

use Psr\Log\LoggerInterface;
use Throwable;
use T2\Http\Request;
use T2\Http\Response;
use function json_encode;
use function nl2br;
use function trim;
use function request;

class ExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger = null;

    /**
     * @var bool
     */
    protected bool $debug = false;

    /**
     * @var array
     */
    public array $dontReport = [];

    /**
     * ExceptionHandler constructor.
     *
     * @param $logger
     * @param $debug
     */
    public function __construct($logger, $debug)
    {
        $this->logger = $logger;
        $this->debug = $debug ?? false;
    }

    /**
     * @param Throwable $exception
     *
     * @return void
     */
    public function report(Throwable $exception): void
    {
        if ($this->shouldntReport($exception)) {
            return;
        }
        $logs = '';
        if ($request = request()) {
            $logs = $request->getRealIp() . ' ' . $request->method() . ' ' . trim($request->fullUrl(), '/');
        }
        $this->logger->error($logs . PHP_EOL . $exception);
    }

    /**
     * @param Request   $request
     * @param Throwable $exception
     *
     * @return Response
     */
    public function render(Request $request, Throwable $exception): Response
    {
        if (method_exists($exception, 'render') && ($response = $exception->render($request))) {
            return $response;
        }
        $code = $exception->getCode();
        if ($request->expectsJson()) {
            $json = ['code' => $code ?: 500, 'msg' => $this->debug ? $exception->getMessage() : 'Server internal error'];
            $this->debug && $json['traces'] = (string)$exception;
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $error = $this->debug ? nl2br((string)$exception) : 'Server internal error';
        return new Response(500, [], $error);
    }

    /**
     * @param Throwable $e
     *
     * @return bool
     */
    protected function shouldntReport(Throwable $e): bool
    {
        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compatible $this->_debug
     *
     * @param string $name
     *
     * @return bool|null
     */
    public function __get(string $name)
    {
        if ($name === '_debug') {
            return $this->debug;
        }
        return null;
    }
}
