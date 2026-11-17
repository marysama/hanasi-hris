<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\ModuleCatalog;
use App\Models\TenantModule;
use App\Models\TenantModuleDepartment;
use App\Services\AuditLogger;
use App\Services\ModuleAccessService;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModuleManagementController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly ModuleAccessService $modules,
        private readonly AuditLogger $audit,
    ) {}

    public function catalog(Request $request)
    {
        $data = $request->validate(['platform' => ['sometimes', 'required', Rule::in([ModuleAccessService::MANAGEMENT, ModuleAccessService::EMPLOYEE_PWA])]]);
        return response()->json(['data' => $this->modules->catalog($data['platform'] ?? null)->map(fn (ModuleCatalog $module) => [
            'code' => $module->code,
            'name' => $module->name,
            'description' => $module->description,
            'status' => $module->status,
            'supported_platforms' => $module->supported_platforms,
            'config_schema' => $module->config_schema,
            'default_enabled' => $module->default_enabled,
        ])]);
    }

    public function tenantModules()
    {
        $this->requireTenantScope();
        $tenantId = $this->context->tenantId();
        $settings = TenantModule::query()->where('tenant_id', $tenantId)->with('departmentLinks:id,tenant_module_id,department_id')->get()->keyBy('module_id');

        return response()->json(['data' => $this->modules->catalog()->map(function (ModuleCatalog $module) use ($settings) {
            $setting = $settings->get($module->id);
            return [
                'code' => $module->code,
                'name' => $module->name,
                'status' => $module->status,
                'supported_platforms' => $module->supported_platforms,
                'is_enabled' => (bool) ($setting?->is_enabled ?? false),
                'department_mode' => $setting?->department_mode ?? 'all_departments',
                'department_ids' => $setting?->departmentLinks->pluck('department_id')->map(fn ($id) => (int) $id)->values() ?? collect(),
                'config' => $setting?->config ?? (object) [],
            ];
        })]);
    }

    public function updateTenantModule(Request $request, string $moduleCode)
    {
        $this->requireTenantScope();
        $data = $request->validate([
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'department_mode' => ['sometimes', 'required', Rule::in(['all_departments', 'selected_departments'])],
            'config' => ['sometimes', 'present', 'array', 'max:50'],
        ]);
        if ($data === []) throw ValidationException::withMessages(['module' => ['Kirim sedikitnya satu pengaturan modul.']]);

        $module = ModuleCatalog::where('code', $moduleCode)->firstOrFail();
        if (($data['is_enabled'] ?? false) && $module->status !== 'available') {
            throw ValidationException::withMessages(['is_enabled' => ['Modul ini masih direncanakan dan belum dapat diaktifkan.']]);
        }
        if (array_key_exists('config', $data)) {
            $this->validateConfig($data['config'], $module);
            $data['config'] = (object) $data['config'];
        }

        return DB::transaction(function () use ($module, $data) {
            $tenantId = $this->context->tenantId();
            $membershipId = $this->context->membership()->id;
            $row = TenantModule::query()->where('tenant_id', $tenantId)->where('module_id', $module->id)->lockForUpdate()->first();
            $before = $row?->only(['is_enabled', 'department_mode', 'config']);
            $row ??= new TenantModule(['tenant_id' => $tenantId, 'module_id' => $module->id, 'is_enabled' => false, 'department_mode' => 'all_departments']);
            $row->fill($data);
            $row->created_by_membership_id ??= $membershipId;
            $row->updated_by_membership_id = $membershipId;
            $row->save();

            $this->audit->record('module.tenant_settings_updated', $row,
                ['before' => $before, 'after' => $row->only(['is_enabled', 'department_mode', 'config'])],
                ['module_code' => $module->code]);

            return response()->json($this->tenantModuleResource($row->fresh(['module', 'departmentLinks'])));
        });
    }

    public function departmentModules(int $department)
    {
        $departmentRow = $this->findDepartment($department);
        $settings = TenantModule::query()->where('tenant_id', $this->context->tenantId())
            ->with('module')->get()->keyBy('module_id');

        return response()->json(['department_id' => $departmentRow->id, 'data' => $this->modules->catalog()->map(function (ModuleCatalog $module) use ($settings, $departmentRow) {
            $setting = $settings->get($module->id);
            return [
                'code' => $module->code,
                'name' => $module->name,
                'status' => $module->status,
                'is_enabled' => (bool) ($setting?->is_enabled ?? false),
                'is_available_for_department' => $setting ? $this->modules->departmentAvailable($setting, (int) $departmentRow->id) : false,
                'department_mode' => $setting?->department_mode ?? 'all_departments',
            ];
        })]);
    }

    public function mapDepartment(int $department, string $moduleCode)
    {
        $departmentRow = $this->findDepartment($department);
        $module = ModuleCatalog::where('code', $moduleCode)->where('status', 'available')->firstOrFail();
        $setting = TenantModule::query()->where('tenant_id', $this->context->tenantId())->where('module_id', $module->id)->firstOrFail();
        if (!$setting->is_enabled || $setting->department_mode !== 'selected_departments') {
            throw ValidationException::withMessages(['module' => ['Aktifkan modul dan pilih mode Department terpilih sebelum membuat pemetaan.']]);
        }

        $link = DB::transaction(function () use ($departmentRow, $setting) {
            $link = TenantModuleDepartment::query()->firstOrCreate([
                'tenant_id' => $this->context->tenantId(),
                'tenant_module_id' => $setting->id,
                'department_id' => $departmentRow->id,
            ], ['created_by_membership_id' => $this->context->membership()->id]);
            if ($link->wasRecentlyCreated) {
                $this->audit->record('module.department_mapped', $link, [], [
                    'module_code' => $setting->module->code,
                    'department_id' => $departmentRow->id,
                    'department_code' => $departmentRow->code,
                ]);
            }
            return $link;
        });

        return response()->json([
            'module_code' => $module->code,
            'department_id' => $departmentRow->id,
            'mapped' => true,
        ], $link->wasRecentlyCreated ? 201 : 200);
    }

    public function unmapDepartment(int $department, string $moduleCode)
    {
        $departmentRow = $this->findDepartment($department);
        $setting = $this->modules->moduleForTenant($this->context->tenantId(), $moduleCode);
        if (!$setting) abort(404);
        $link = TenantModuleDepartment::query()->where('tenant_id', $this->context->tenantId())
            ->where('tenant_module_id', $setting->id)->where('department_id', $departmentRow->id)->firstOrFail();
        $this->audit->record('module.department_unmapped', $link, ['before' => ['department_id' => $departmentRow->id]], ['module_code' => $moduleCode]);
        $link->delete();

        return response()->json(['module_code' => $moduleCode, 'department_id' => $departmentRow->id, 'mapped' => false]);
    }

    public function menu(Request $request)
    {
        $data = $request->validate(['platform' => ['required', Rule::in([ModuleAccessService::MANAGEMENT, ModuleAccessService::EMPLOYEE_PWA])]]);
        return response()->json(['platform' => $data['platform'], 'data' => $this->modules->menu($this->context->membership(), $data['platform'])]);
    }

    private function findDepartment(int $id): Department
    {
        $department = Department::query()->where('tenant_id', $this->context->tenantId())->whereKey($id)->where('status', 'active')->firstOrFail();
        abort_unless($this->scopes->allows($this->context->membership(), 'department', $id), 403);
        return $department;
    }

    private function requireTenantScope(): void
    {
        abort_unless($this->scopes->hasTenantAccess($this->context->membership()), 403);
    }

    private function tenantModuleResource(TenantModule $setting): array
    {
        return [
            'module' => ['code' => $setting->module->code, 'name' => $setting->module->name, 'status' => $setting->module->status],
            'is_enabled' => $setting->is_enabled,
            'department_mode' => $setting->department_mode,
            'department_ids' => $setting->departmentLinks->pluck('department_id')->map(fn ($id) => (int) $id)->values(),
            'config' => $setting->config ?? (object) [],
        ];
    }

    private function validateConfig(array $config, ModuleCatalog $module): void
    {
        if (array_is_list($config) && $config !== []) {
            throw ValidationException::withMessages(['config' => ['Konfigurasi modul harus berupa objek dengan nama properti.']]);
        }
        if (strlen(json_encode($config, JSON_THROW_ON_ERROR)) > 16384) {
            throw ValidationException::withMessages(['config' => ['Ukuran konfigurasi modul melebihi batas 16 KB.']]);
        }

        $schema = $module->config_schema ?? [];
        $properties = $schema['properties'] ?? [];
        if (($schema['additionalProperties'] ?? false) === false && array_diff(array_keys($config), array_keys($properties))) {
            throw ValidationException::withMessages(['config' => ['Konfigurasi berisi properti yang tidak dikenali modul.']]);
        }
        foreach ($schema['required'] ?? [] as $required) {
            if (!array_key_exists($required, $config)) throw ValidationException::withMessages(["config.{$required}" => ['Properti wajib konfigurasi modul belum diisi.']]);
        }
        foreach ($config as $key => $value) {
            if (!isset($properties[$key])) continue;
            $rule = $properties[$key];
            $type = $rule['type'] ?? null;
            $valid = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                default => true,
            };
            if (!$valid) throw ValidationException::withMessages(["config.{$key}" => ['Tipe data konfigurasi tidak sesuai skema modul.']]);
            if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) throw ValidationException::withMessages(["config.{$key}" => ['Nilai konfigurasi tidak termasuk pilihan yang diizinkan.']]);
            if (is_string($value) && isset($rule['maxLength']) && mb_strlen($value) > $rule['maxLength']) throw ValidationException::withMessages(["config.{$key}" => ['Teks konfigurasi terlalu panjang.']]);
            if (is_string($value) && isset($rule['minLength']) && mb_strlen($value) < $rule['minLength']) throw ValidationException::withMessages(["config.{$key}" => ['Teks konfigurasi terlalu pendek.']]);
            if (is_numeric($value) && isset($rule['maximum']) && $value > $rule['maximum']) throw ValidationException::withMessages(["config.{$key}" => ['Nilai konfigurasi melewati batas maksimum.']]);
            if (is_numeric($value) && isset($rule['minimum']) && $value < $rule['minimum']) throw ValidationException::withMessages(["config.{$key}" => ['Nilai konfigurasi di bawah batas minimum.']]);
        }
    }
}
