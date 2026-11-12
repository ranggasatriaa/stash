<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Lets an agent discover its owner context and granted abilities. */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json(['data' => [
            'user' => ['id' => $request->user()->id, 'name' => $request->user()->name, 'timezone' => $request->user()->timezone],
            'token' => $token instanceof PersonalAccessToken ? [
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at?->toIso8601ZuluString(),
            ] : null,
        ]]);
    }
}
