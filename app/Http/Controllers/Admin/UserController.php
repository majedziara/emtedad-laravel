<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\SyncUserRolesRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UserIndexRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends ApiController
{
    public function __construct(private readonly UserService $service) {}

    public function index(UserIndexRequest $request): JsonResponse
    {
        $users = $this->service->index($request->validated());

        return $this->success(['users' => UserResource::collection($users), 'pagination' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'per_page' => $users->perPage(), 'total' => $users->total()]]);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success(new UserResource($this->service->show($user)));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return $this->success(new UserResource($this->service->store($request->validated())), __('api.user_created'), 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        return $this->success(new UserResource($this->service->update($request->user(), $user, $request->validated())));
    }

    public function syncRoles(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        return $this->success(new UserResource($this->service->syncRoles($request->user(), $user, $request->validated('roles'))));
    }
}
