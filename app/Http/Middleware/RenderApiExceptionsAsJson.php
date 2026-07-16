<?php

declare(strict_types=1);

namespace Waterline\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RenderApiExceptionsAsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        try {
            return $next($request);
        } catch (Throwable $exception) {
            return $this->renderException($request, $exception);
        }
    }

    public function renderException(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof ValidationException) {
            return $this->validationExceptionResponse($exception);
        }

        if ($exception instanceof AuthenticationException) {
            return $this->authenticationExceptionResponse($exception);
        }

        if ($exception instanceof AuthorizationException) {
            return $this->authorizationExceptionResponse($exception);
        }

        if ($exception instanceof HttpResponseException) {
            return $this->responseExceptionResponse($exception);
        }

        return $this->jsonExceptionResponse($exception);
    }

    private function validationExceptionResponse(ValidationException $exception): Response
    {
        $response = $exception->response;

        if ($response instanceof JsonResponse) {
            return $response;
        }

        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], $exception->status);
    }

    private function authenticationExceptionResponse(AuthenticationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $this->frameworkMessage($exception, 'Unauthenticated.'),
        ], 401);
    }

    private function authorizationExceptionResponse(AuthorizationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $this->frameworkMessage($exception, 'This action is unauthorized.'),
        ], $this->authorizationStatus($exception));
    }

    private function responseExceptionResponse(HttpResponseException $exception): Response
    {
        $response = $exception->getResponse();

        if ($response instanceof JsonResponse) {
            return $response;
        }

        return response()->json([
            'message' => $this->message($exception, $response->getStatusCode()),
            'error' => $this->errorCode($exception, $response->getStatusCode()),
        ], $response->getStatusCode(), $this->responseExceptionHeaders($response));
    }

    /**
     * @return array<string, list<string|null>>
     */
    private function responseExceptionHeaders(Response $response): array
    {
        $headers = $response->headers->all();
        unset($headers['content-type'], $headers['content-length']);

        return $headers;
    }

    private function jsonExceptionResponse(Throwable $exception): JsonResponse
    {
        $status = $this->statusCode($exception);

        return response()->json([
            'message' => $this->message($exception, $status),
            'error' => $this->errorCode($exception, $status),
        ], $status, $this->headers($exception));
    }

    private function statusCode(Throwable $exception): int
    {
        if ($exception instanceof ModelNotFoundException) {
            return 404;
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        return 500;
    }

    private function message(Throwable $exception, int $status): string
    {
        if ($status === 404) {
            return 'Waterline API resource not found.';
        }

        if ($status >= 500) {
            return 'Waterline API request failed.';
        }

        $message = trim($exception->getMessage());

        return $message === '' ? Response::$statusTexts[$status] ?? 'Waterline API request failed.' : $message;
    }

    private function frameworkMessage(Throwable $exception, string $fallback): string
    {
        $message = trim($exception->getMessage());

        return $message === '' ? $fallback : $message;
    }

    private function authorizationStatus(AuthorizationException $exception): int
    {
        if (method_exists($exception, 'hasStatus')
            && $exception->hasStatus()
            && method_exists($exception, 'status')) {
            $status = $exception->status();

            if (is_int($status)) {
                return $status;
            }
        }

        return 403;
    }

    private function errorCode(Throwable $exception, int $status): string
    {
        if ($status === 404) {
            return 'not_found';
        }

        if ($status >= 500) {
            return 'waterline_api_error';
        }

        return $exception instanceof HttpExceptionInterface ? 'http_error' : 'waterline_api_error';
    }

    /**
     * @return array<string, string>
     */
    private function headers(Throwable $exception): array
    {
        return $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
    }
}
