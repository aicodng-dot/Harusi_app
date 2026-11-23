<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DeviceApiResponse
{
    public static function success(string $message, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status)
            ->withHeaders(self::headers());
    }

    public static function error(string $message, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => $errors ?: (object) []], $status)
            ->withHeaders(array_merge($headers, self::headers()));
    }

    public static function exception(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return self::error('The submitted data is invalid.', 422, $exception->errors());
        }

        $status = match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };

        $message = match ($status) {
            401 => 'Unauthenticated.',
            403 => 'This action is not authorized.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            429 => 'Too many requests. Try again later.',
            default => 'The request could not be completed.',
        };

        return self::error($message, $status, [], $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
    }

    public static function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
    }
}
