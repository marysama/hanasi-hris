<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleManagementController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        $this->requireTenantScope();
        $roles = Role::query()->where('tenant_id', $this->tenantId())
            ->with(['permissions' => fn ($query) => $query->orderBy('permissions.code')])
            ->orderBy('code')->paginate(50);

        return response()->json($roles);
    }

    public function show(int $role)
    {
        $row = $this->findRole($role);
        $this->requireTenantScope();
        return response()->json($row->load(['permissions' => fn ($query) => $query->orderBy('permissions.code')]));
    }

    public function permissionCatalog()
    {
        $this->requireTenantScope();
        $grantable = $this->permissionPairs($this->context->membership());
        $rows = Permission::query()->where('platform', 'management_dashboard')
            ->whereIn('code', collect($grantable)->where('platform', 'management_dashboard')->pluck('code'))
            ->orderBy('code')->get(['code', 'platform', 'description']);
        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $this->requireTenantScope();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_.-]*$/', Rule::unique('roles', 'code')->where('tenant_id', $this->tenantId())],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $row = Role::create($data + ['tenant_id' => $this->tenantId(), 'status' => 'active']);
        $this->audit->record('access.role_created', $row, [], ['code' => $row->code, 'name' => $row->name]);
        return response()->json($row, 201);
    }

    public function update(Request $request, int $role)
    {
        $row = $this->findRole($role);
        $this->requireTenantScope();
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_.-]*$/', Rule::unique('roles', 'code')->where('tenant_id', $this->tenantId())->ignore($row->id)],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ]);
        if (!$data) throw ValidationException::withMessages(['role' => ['Tidak ada perubahan yang dikirim.']]);

        return DB::transaction(function () use ($row, $data) {
            $row = $this->findRole((int) $row->id, true);
            $before = $row->only(array_keys($data));
            if (($data['status'] ?? null) === 'inactive' && $this->hasCurrentMembers($row)) {
                throw ValidationException::withMessages(['status' => ['Role yang masih dipakai membership aktif tidak dapat dinonaktifkan. Cabut assignment role terlebih dahulu.']]);
            }
            $row->fill($data)->save();
            $this->ensureTenantAccessAdministratorRemains();
            $this->audit->record('access.role_updated', $row, ['before' => $before, 'after' => $row->only(array_keys($data))]);
            return response()->json($row->fresh());
        });
    }

    public function rolePermissions(int $role)
    {
        $row = $this->findRole($role);
        $this->requireTenantScope();
        return response()->json(['data' => $row->permissions()->orderBy('permissions.code')->get(['permissions.code', 'permissions.platform', 'permissions.description'])]);
    }

    public function replaceRolePermissions(Request $request, int $role)
    {
        $row = $this->findRole($role);
        $this->requireTenantScope();
        $data = $request->validate([
            'permission_codes' => ['required', 'array', 'max:250'],
            'permission_codes.*' => ['required', 'string', 'distinct', 'max:120'],
        ]);

        $permissions = Permission::query()->whereIn('code', $data['permission_codes'])->get(['id', 'code', 'platform']);
        if ($permissions->count() !== count($data['permission_codes']) || $permissions->contains(fn ($permission) => $permission->platform !== 'management_dashboard')) {
            throw ValidationException::withMessages(['permission_codes' => ['Pilih permission Management Dashboard yang tersedia.']]);
        }

        return DB::transaction(function () use ($row, $permissions) {
            $row = $this->findRole((int) $row->id, true);
            $current = $row->permissions()->where('permissions.platform', 'management_dashboard')->get(['permissions.id', 'permissions.code']);
            $currentCodes = $current->pluck('code')->all();
            $requestedCodes = $permissions->pluck('code')->all();
            $addedCodes = array_values(array_diff($requestedCodes, $currentCodes));
            $actorPairs = $this->permissionPairs($this->context->membership());
            $grantableCodes = collect($actorPairs)->where('platform', 'management_dashboard')->pluck('code')->all();
            if (array_diff($addedCodes, $grantableCodes)) abort(403, 'You cannot grant permissions beyond your own authority.');

            $removedIds = $current->whereNotIn('code', $requestedCodes)->pluck('id')->all();
            if ($removedIds) $row->permissions()->detach($removedIds);
            $newIds = $permissions->whereIn('code', $addedCodes)->pluck('id')->all();
            if ($newIds) $row->permissions()->syncWithoutDetaching(array_fill_keys($newIds, ['tenant_id' => $this->tenantId()]));

            $this->ensureTenantAccessAdministratorRemains();
            $this->audit->record('access.role_permissions_replaced', $row,
                ['before' => ['permission_codes' => $currentCodes], 'after' => ['permission_codes' => $requestedCodes]],
                ['permission_count' => count($requestedCodes)]);
            return response()->json(['role_id' => $row->id, 'permission_codes' => $row->permissions()->orderBy('permissions.code')->pluck('permissions.code')]);
        });
    }

    public function membershipRoles(int $membership)
    {
        $target = $this->findMembership($membership);
        $this->assertMembershipWithinActorScope($target);
        return response()->json(['data' => $target->roles()->withPivot(['effective_from', 'effective_to', 'assigned_by'])->orderBy('code')->get()]);
    }

    public function assignMembershipRole(Request $request, int $membership)
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $target = $this->findMembership($membership);
        $role = $this->findRole((int) $data['role_id']);
        if ($target->id === $this->context->membership()->id) {
            throw ValidationException::withMessages(['membership' => ['Anda tidak dapat mengubah role membership yang sedang digunakan.']]);
        }
        $this->assertMembershipWithinActorScope($target);
        $this->assertRolePermissionsWithinActor($role);
        if ($target->status !== 'active' && $target->status !== 'pending') {
            throw ValidationException::withMessages(['membership' => ['Membership tidak dapat diberi role dalam status ini.']]);
        }
        if ($role->status !== 'active') throw ValidationException::withMessages(['role_id' => ['Role harus aktif.']]);

        return DB::transaction(function () use ($target, $role, $data) {
            $pivot = DB::table('membership_roles')->where('tenant_id', $this->tenantId())->where('membership_id', $target->id)->where('role_id', $role->id)->first();
            if ($pivot && ($pivot->effective_to === null || $pivot->effective_to >= now()->toDateTimeString())) {
                throw ValidationException::withMessages(['role_id' => ['Role sudah diberikan untuk membership ini.']]);
            }
            $before = $pivot ? ['effective_from' => $pivot->effective_from, 'effective_to' => $pivot->effective_to] : null;
            $values = [
                'effective_from' => $data['effective_from'] ?? now(),
                'effective_to' => $data['effective_to'] ?? null,
                'assigned_by' => auth()->id(),
                'tenant_id' => $this->tenantId(),
            ];
            if ($pivot) {
                $target->roles()->updateExistingPivot($role->id, $values);
            } else {
                $target->roles()->attach($role->id, $values + ['created_at' => now()]);
            }
            $this->ensureTenantAccessAdministratorRemains();
            $this->audit->record('access.membership_role_assigned', $target,
                ['before' => ['role_id' => $role->id, 'dates' => $before], 'after' => ['role_id' => $role->id, 'dates' => $values]],
                ['membership_id' => $target->id, 'role_code' => $role->code]);
            return response()->json(['membership_id' => $target->id, 'role' => $role->code, 'effective_from' => $values['effective_from'], 'effective_to' => $values['effective_to']], 201);
        });
    }

    public function revokeMembershipRole(int $membership, int $role)
    {
        $target = $this->findMembership($membership);
        $roleRow = $this->findRole($role);
        if ($target->id === $this->context->membership()->id) {
            throw ValidationException::withMessages(['membership' => ['Anda tidak dapat mengubah role membership yang sedang digunakan.']]);
        }
        $this->assertMembershipWithinActorScope($target);
        $pivot = DB::table('membership_roles')->where('tenant_id', $this->tenantId())->where('membership_id', $target->id)->where('role_id', $roleRow->id)->first();
        if (!$pivot) abort(404);

        return DB::transaction(function () use ($target, $roleRow, $pivot) {
            $before = ['effective_from' => $pivot->effective_from, 'effective_to' => $pivot->effective_to];
            $endedAt = now()->subSecond();
            DB::table('membership_roles')->where('tenant_id', $this->tenantId())->where('membership_id', $target->id)->where('role_id', $roleRow->id)
                ->update(['effective_to' => $endedAt]);
            if ($target->status === 'active' && !$this->hasAnotherEffectiveRole($target, (int) $roleRow->id)) {
                throw ValidationException::withMessages(['role' => ['Membership aktif harus mempertahankan minimal satu role yang berlaku.']]);
            }
            $this->ensureTenantAccessAdministratorRemains();
            $this->audit->record('access.membership_role_revoked', $target,
                ['before' => ['role_id' => $roleRow->id, 'dates' => $before], 'after' => ['role_id' => $roleRow->id, 'effective_to' => $endedAt->toIso8601String()]],
                ['membership_id' => $target->id, 'role_code' => $roleRow->code]);
            return response()->json(['message' => 'Role dicabut.', 'role' => $roleRow->code, 'effective_to' => $endedAt->toIso8601String()]);
        });
    }

    private function findRole(int $id, bool $lock = false): Role
    {
        $query = Role::query()->where('tenant_id', $this->tenantId())->whereKey($id);
        if ($lock) $query->lockForUpdate();
        return $query->firstOrFail();
    }

    private function findMembership(int $id): TenantMembership
    {
        return TenantMembership::query()->where('tenant_id', $this->tenantId())->whereKey($id)->firstOrFail();
    }

    private function hasCurrentMembers(Role $role): bool
    {
        return DB::table('membership_roles as mr')->join('tenant_memberships as tm', function ($join) {
            $join->on('tm.id', '=', 'mr.membership_id')->on('tm.tenant_id', '=', 'mr.tenant_id');
        })->where('mr.tenant_id', $this->tenantId())->where('mr.role_id', $role->id)->where('tm.status', 'active')
            ->where(fn ($query) => $query->whereNull('mr.effective_from')->orWhere('mr.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('mr.effective_to')->orWhere('mr.effective_to', '>=', now()))->exists();
    }

    private function permissionPairs(TenantMembership $membership): array
    {
        return DB::table('membership_roles as mr')->join('roles as r', function ($join) {
            $join->on('r.id', '=', 'mr.role_id')->on('r.tenant_id', '=', 'mr.tenant_id');
        })->join('role_permissions as rp', function ($join) {
            $join->on('rp.role_id', '=', 'r.id')->on('rp.tenant_id', '=', 'r.tenant_id');
        })->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.tenant_id', $membership->tenant_id)->where('mr.membership_id', $membership->id)->where('r.status', 'active')
            ->where(fn ($query) => $query->whereNull('mr.effective_from')->orWhere('mr.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('mr.effective_to')->orWhere('mr.effective_to', '>=', now()))
            ->select('p.platform', 'p.code')->distinct()->get()->map(fn ($row) => ['platform' => $row->platform, 'code' => $row->code])->all();
    }

    private function assertRolePermissionsWithinActor(Role $role): void
    {
        $actor = collect($this->permissionPairs($this->context->membership()))->map(fn ($row) => $row['platform'].':'.$row['code'])->all();
        $requested = $role->permissions()->get(['permissions.platform', 'permissions.code'])
            ->map(fn ($row) => $row->platform.':'.$row->code)->all();
        if (array_diff($requested, $actor)) abort(403, 'Role grants permissions beyond your own authority.');
    }

    private function assertMembershipWithinActorScope(TenantMembership $target): void
    {
        if (!$this->scopes->canManageMembership($this->context->membership(), $target)) abort(403, 'Target membership is outside your data scope.');
    }

    private function hasAnotherEffectiveRole(TenantMembership $membership, int $exceptRoleId): bool
    {
        return DB::table('membership_roles as mr')->join('roles as r', function ($join) {
            $join->on('r.id', '=', 'mr.role_id')->on('r.tenant_id', '=', 'mr.tenant_id');
        })->where('mr.tenant_id', $membership->tenant_id)->where('mr.membership_id', $membership->id)->where('mr.role_id', '<>', $exceptRoleId)->where('r.status', 'active')
            ->where(fn ($query) => $query->whereNull('mr.effective_from')->orWhere('mr.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('mr.effective_to')->orWhere('mr.effective_to', '>=', now()))->exists();
    }

    private function ensureTenantAccessAdministratorRemains(): void
    {
        $tenant = Tenant::query()->whereKey($this->tenantId())->firstOrFail();
        $admins = TenantMembership::query()->where('tenant_id', $tenant->id)->where('status', 'active')->get();
        foreach ($admins as $membership) {
            if ($this->scopes->hasTenantAccess($membership)
                && $membership->hasPermission('roles.manage', 'management_dashboard')
                && $membership->hasPermission('permissions.assign', 'management_dashboard')) return;
        }
        throw ValidationException::withMessages(['access' => ['Perubahan ini akan menghilangkan seluruh administrator akses tenant. Pertahankan minimal satu membership aktif dengan scope tenant, roles.manage, dan permissions.assign.']]);
    }

    private function requireTenantScope(): void
    {
        if (!$this->scopes->hasTenantAccess($this->context->membership())) abort(403, 'Perubahan role dan permission memerlukan scope tenant.');
    }

    private function tenantId(): int { return $this->context->tenantId(); }
}
