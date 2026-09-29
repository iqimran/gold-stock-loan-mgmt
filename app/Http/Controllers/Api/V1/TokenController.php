<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\IssueApiToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    public function store(IssueTokenRequest $request, IssueApiToken $issueApiToken): JsonResponse
    {
        $token = $issueApiToken->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name')->toString(),
        );

        return response()->json(['token' => $token, 'token_type' => 'Bearer'], Response::HTTP_CREATED);
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
