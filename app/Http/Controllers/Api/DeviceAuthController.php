<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceTokenService;
use App\Services\LoginIdentifier;
use App\Support\DeviceApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Symfony\Component\HttpFoundation\Response;

class DeviceAuthController extends Controller
{
    public function login(Request $request, LoginIdentifier $identifiers, DeviceTokenService $tokens): JsonResponse
    {
        $field = ! $request->exists('identifier') && $request->exists('email') ? 'email' : 'identifier';
        $validated = $request->validate([
            $field => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $token = (new Timebox)->call(function () use ($identifiers, $tokens, $validated, $field) {
            $identity = $identifiers->resolve($validated[$field]);

            return $identity === null ? null : $tokens->issue($identity, $validated['password'], $validated['device_name']);
        }, 200000);

        if ($token === null) {
            return DeviceApiResponse::error('The provided credentials do not match our records.', 401);
        }

        return DeviceApiResponse::success('Signed in.', [
            'user' => $tokens->profile($token->accessToken->tokenable),
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at->toISOString(),
        ], 201);
    }

    public function me(Request $request, DeviceTokenService $tokens): JsonResponse
    {
        return DeviceApiResponse::success('Authenticated.', ['user' => $tokens->profile($request->user())]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent()->withHeaders(DeviceApiResponse::headers());
    }
}
