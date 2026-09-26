<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController extends ApiController
{
    public function __construct(private readonly RoleService $service) {}

    public function index(): JsonResponse
    {
        return $this->success(RoleResource::collection($this->service->index()));
    }

    public function permissions(): JsonResponse
    {
        return $this->success($this->service->permissions());
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        return $this->success(new RoleResource($this->service->store($request->validated())), __('api.role_created'), 201);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        return $this->success(new RoleResource($this->service->update($role, $request->validated())));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->service->delete($role);

        return $this->success(null, __('api.role_deleted'));
    }
}
