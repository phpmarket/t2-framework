<?php

namespace T2\Exception;

use RuntimeException;
use Throwable;
use T2\Http\Request;
use T2\Http\Response;
use function json_encode;

class BusinessException extends RuntimeException
{
    /**
     * @var array
     */
    protected array $data = [];

    /**
     * @var bool
     */
    protected bool $debug = false;

    /**
     * Render an exception into an HTTP response.
     *
     * @param Request $request
     *
     * @return Response|null
     */
    public function render(Request $request): ?Response
    {
        if ($request->expectsJson()) {
            $code = $this->getCode();
            $json = ['code' => $code ?: 500, 'msg' => $this->getMessage(), 'data' => $this->data];
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        return new Response(200, [], $this->getMessage());
    }

    /**
     * Set data.
     *
     * @param array|null $data
     *
     * @return array|$this
     */
    public function data(?array $data = null): array|static
    {
        if ($data === null) {
            return $this->data;
        }
        $this->data = $data;
        return $this;
    }

    /**
     * Set debug.
     *
     * @param bool|null $value
     *
     * @return $this|bool
     */
    public function debug(?bool $value = null): bool|static
    {
        if ($value === null) {
            return $this->debug;
        }
        $this->debug = $value;
        return $this;
    }

    /**
     * Get data.
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Translate message.
     *
     * @param string      $message
     * @param array       $parameters
     * @param string|null $domain
     * @param string|null $locale
     *
     * @return string
     */
    protected function trans(string $message, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $args = [];
        foreach ($parameters as $key => $parameter) {
            $args[":$key"] = $parameter;
        }
        try {
            $message = trans($message, $args, $domain, $locale);
        } catch (Throwable $e) {
        }
        foreach ($parameters as $key => $value) {
            $message = str_replace(":$key", $value, $message);
        }
        return $message;
    }

}
