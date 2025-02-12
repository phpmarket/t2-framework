<?php

namespace T2\Exception;

use Throwable;
use T2\Http\Request;
use T2\Http\Response;

interface ExceptionHandlerInterface
{
    /**
     * @param Throwable $exception
     *
     * @return void
     */
    public function report(Throwable $exception): void;

    /**
     * @param Request   $request
     * @param Throwable $exception
     *
     * @return Response
     */
    public function render(Request $request, Throwable $exception): Response;
}