<?php

namespace App\Exception;

use Throwable;
use T2\Exception\ExceptionHandler;
use T2\Http\Request;
use T2\Http\Response;
use T2\Exception\BusinessException;

class Handler extends ExceptionHandler
{
    /**
     * @var array|string[]
     */
    public array $dontReport = [
        BusinessException::class
    ];

    /**
     * @param Throwable $exception
     *
     * @return void
     */
    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    /**
     * @param Request   $request
     * @param Throwable $exception
     *
     * @return Response
     */
    public function render(Request $request, Throwable $exception): Response
    {
        return parent::render($request, $exception);
    }

}