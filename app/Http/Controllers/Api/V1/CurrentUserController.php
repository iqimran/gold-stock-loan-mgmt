<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return (new UserResource($user))
            ->additional(['abilities' => $user->effectivePermissionNames()])
            ->response();
    }
}
