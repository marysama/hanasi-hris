<?php

namespace App\Console\Commands;

use App\Models\AttendanceLocationPolicy;
use App\Models\AttendanceShift;
use App\Models\ClientAccount;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LegalEntity;
use App\Models\OrgNode;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserScopeGrant;
use App\Models\WorkLocation;
use App\Services\EmployeeAssignmentService;
use App\Services\EmployeeNumberService;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BootstrapDevelopmentTrialCommand extends Command
{
    protected $signature = 'hanasi:dev:bootstrap
        {--tenant-code=hanasi-trial : Unique tenant code for this fake local trial}
        {--admin-email=hr-admin@hanasi.test : Development HR administrator email}
        {--employee-email=karyawan-uji@hanasi.test : Development employee email}';

    protected $description = 'Interactively create an isolated fake-data tenant for local browser testing.';

    public function handle(EmployeeNumberService $numbers, EmployeeAssignmentService $assignments): int
    {
        if (!$this->isSafeLocalDatabase()) {
            $this->error('Refused. This command only runs in APP_ENV=local against the loopback hanasi_hris_dev database.');
            return self::FAILURE;
        }

        $tenantCode = strtolower(trim((string) $this->option('tenant-code')));
        $adminEmail = strtolower(trim((string) $this->option('admin-email')));
        $employeeEmail = strtolower(trim((string) $this->option('employee-email')));

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,49}$/', $tenantCode)
            || !str_ends_with($adminEmail, '.test')
            || !str_ends_with($employeeEmail, '.test')
            || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
            || !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)
            || $adminEmail === $employeeEmail) {
            $this->error('Gunakan tenant code valid dan dua alamat email unik yang berakhiran .test.');
            return self::FAILURE;
        }
        if (Tenant::where('code', $tenantCode)->exists()
            || User::whereIn('email', [$adminEmail, $employeeEmail])->exists()) {
            $this->error('Tenant code atau email sudah dipakai. Command tidak mengubah data yang ada.');
            return self::FAILURE;
        }

        $adminPassword = $this->secret('Password HR development (tidak ditampilkan)');
        $adminConfirmation = $this->secret('Ulangi password HR development');
        $employeePassword = $this->secret('Password karyawan development (tidak ditampilkan)');
        $employeeConfirmation = $this->secret('Ulangi password karyawan development');
        if (!$adminPassword || $adminPassword !== $adminConfirmation
            || !$employeePassword || $employeePassword !== $employeeConfirmation
            || strlen($adminPassword) < 12 || strlen($employeePassword) < 12) {
            $this->error('Password harus cocok dan panjangnya minimal 12 karakter.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->warn('Akan membuat tenant dan akun development dengan nama serta data fiktif.');
        $this->line('Tidak akan mengubah tenant yang sudah ada. Email uji wajib memakai domain .test.');
        if ($this->ask('Ketik BOOTSTRAP untuk melanjutkan') !== 'BOOTSTRAP') {
            $this->info('Dibatalkan. Tidak ada data yang dibuat.');
            return self::SUCCESS;
        }

        try {
            $result = DB::transaction(function () use ($tenantCode, $adminEmail, $employeeEmail, $adminPassword, $employeePassword, $numbers, $assignments): array {
                app(PermissionCatalogSeeder::class)->run();

                $tenant = Tenant::create(['code' => $tenantCode, 'name' => 'Hanasi Trial (DATA DEVELOPMENT)', 'status' => 'active']);
                $entity = LegalEntity::create([
                    'tenant_id' => $tenant->id, 'code' => 'HANASI-DEV',
                    'legal_name' => 'Hanasi Demo Employer (DATA DEVELOPMENT)', 'display_name' => 'Hanasi Demo',
                    'status' => 'active',
                ]);
                $company = OrgNode::create([
                    'tenant_id' => $tenant->id, 'node_type' => 'company', 'parent_id' => null,
                    'legal_entity_id' => $entity->id, 'code' => 'HANASI-DEV', 'name' => 'Hanasi Demo Company',
                    'status' => 'active',
                ]);
                $building = OrgNode::create([
                    'tenant_id' => $tenant->id, 'node_type' => 'building', 'parent_id' => $company->id,
                    'legal_entity_id' => null, 'code' => 'TRIAL-BUILDING', 'name' => 'Trial Building (DATA DEVELOPMENT)',
                    'status' => 'active',
                ]);
                $client = ClientAccount::create([
                    'tenant_id' => $tenant->id, 'code' => 'DEMO-CLIENT',
                    'legal_name' => 'Demo Client (DATA DEVELOPMENT)', 'display_name' => 'Demo Client',
                    'status' => 'active',
                ]);
                $location = WorkLocation::create([
                    'tenant_id' => $tenant->id, 'client_account_id' => $client->id, 'org_node_id' => $building->id,
                    'code' => 'TRIAL-SITE', 'name' => 'Trial Work Location (DATA DEVELOPMENT)',
                    'address' => 'Alamat fiktif untuk pengujian lokal', 'latitude' => null, 'longitude' => null,
                    'attendance_radius_meters' => 150, 'status' => 'active',
                ]);
                $department = Department::create([
                    'tenant_id' => $tenant->id, 'org_node_id' => null, 'code' => 'OPS-DEV',
                    'name' => 'Operations (DATA DEVELOPMENT)', 'status' => 'active',
                ]);
                $position = Position::create([
                    'tenant_id' => $tenant->id, 'code' => 'WORKER-DEV',
                    'name' => 'Karyawan Uji (DATA DEVELOPMENT)', 'reports_to_position_id' => null,
                    'status' => 'active',
                ]);

                $admin = User::create(['name' => 'HR Admin Uji (DATA DEVELOPMENT)', 'email' => $adminEmail, 'password' => $adminPassword]);
                $employeeUser = User::create(['name' => 'Karyawan Uji (DATA DEVELOPMENT)', 'email' => $employeeEmail, 'password' => $employeePassword]);
                $adminMembership = $this->createMembership($tenant, $admin, 'tenant_admin', 'Tenant Administrator', 'management_dashboard', $admin->id);
                $employeeMembership = $this->createMembership($tenant, $employeeUser, 'employee_self_demo', 'Employee Self Service Demo', 'employee_pwa', $admin->id);

                $employee = Employee::create([
                    'tenant_id' => $tenant->id, 'employee_number' => $numbers->next((int) $tenant->id),
                    'user_id' => $employeeUser->id, 'legal_entity_id' => $entity->id, 'department_id' => $department->id,
                    'position_id' => $position->id, 'full_name' => 'Karyawan Uji (DATA DEVELOPMENT)',
                    'email' => $employeeEmail, 'status' => 'active', 'hire_date' => now()->toDateString(),
                ]);

                $trialStart = now()->addMinutes(2);
                $effectiveFrom = $trialStart->format('Y-m-d\TH:i:sP');
                $assignment = $assignments->create($employee, [
                    'assignment_type' => 'primary', 'legal_entity_id' => $entity->id,
                    'client_account_id' => $client->id, 'work_location_id' => $location->id,
                    'department_id' => $department->id, 'org_node_id' => $building->id,
                    'position_id' => $position->id, 'supervisor_employee_id' => null,
                    'effective_from' => $effectiveFrom, 'effective_until' => null,
                    'reason' => 'Penempatan data development untuk uji browser.',
                ], $adminMembership);

                $shift = AttendanceShift::create([
                    'tenant_id' => $tenant->id, 'code' => 'TRIAL-DAY',
                    'name' => 'Trial Day Shift (DATA DEVELOPMENT)', 'starts_at' => $trialStart->copy()->subMinutes(5)->format('H:i:s'),
                    'ends_at' => $trialStart->copy()->addHours(2)->format('H:i:s'), 'late_tolerance_minutes' => 10,
                    'work_days' => [1, 2, 3, 4, 5, 6, 7], 'status' => 'active',
                    'created_by_membership_id' => $adminMembership->id,
                ]);
                EmployeeShiftAssignment::create([
                    'tenant_id' => $tenant->id, 'employee_assignment_id' => $assignment->id,
                    'shift_id' => $shift->id, 'effective_from' => $trialStart,
                    'effective_until' => null, 'created_by_membership_id' => $adminMembership->id,
                ]);
                AttendanceLocationPolicy::create([
                    'tenant_id' => $tenant->id, 'work_location_id' => $location->id,
                    'gps_mode' => 'optional', 'gps_accuracy_limit_meters' => null,
                    'selfie_required_check_in' => false, 'selfie_required_check_out' => false,
                    'is_active' => true, 'configured_by_membership_id' => $adminMembership->id,
                ]);

                DB::table('audit_logs')->insert([
                    'tenant_id' => $tenant->id, 'actor_user_id' => $admin->id,
                    'action' => 'development.trial_bootstrapped', 'subject_type' => Tenant::class,
                    'subject_id' => (string) $tenant->id,
                    'changes' => json_encode(['created' => ['tenant' => $tenantCode, 'fake_data' => true]], JSON_THROW_ON_ERROR),
                    'context' => json_encode(['source' => 'hanasi:dev:bootstrap'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return compact('tenant', 'admin', 'employeeUser', 'employee', 'adminMembership', 'employeeMembership');
            });
        } catch (Throwable $error) {
            report($error);
            $this->error('Bootstrap gagal dan perubahan database dibatalkan. Lihat log Laravel untuk rincian teknis.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Tenant development berhasil dibuat.');
        $this->line('Tenant: ' . $result['tenant']->code);
        $this->line('HR login: ' . $result['admin']->email);
        $this->line('Employee login: ' . $result['employeeUser']->email);
        $this->line('Employee Number: ' . $result['employee']->employee_number);
        $this->line('Membership HR: aktif, role tenant_admin, tenant scope');
        $this->line('Membership Employee: aktif, role employee_self_demo, Employee PWA permissions');
        $this->line('Penempatan dan shift mulai sekitar dua menit setelah command dijalankan.');
        $this->warn('Simpan password yang Anda masukkan. Password tidak dicetak kembali oleh command.');
        return self::SUCCESS;
    }

    private function createMembership(Tenant $tenant, User $user, string $roleCode, string $roleName, string $platform, int $grantedBy): TenantMembership
    {
        $role = Role::create(['tenant_id' => $tenant->id, 'code' => $roleCode, 'name' => $roleName, 'status' => 'active']);
        $permissionIds = Permission::query()->where('platform', $platform)->pluck('id')->all();
        $role->permissions()->syncWithPivotValues($permissionIds, ['tenant_id' => $tenant->id]);

        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'pending']);
        $membership->roles()->attach($role->id, ['tenant_id' => $tenant->id, 'assigned_by' => $grantedBy]);
        UserScopeGrant::create([
            'tenant_id' => $tenant->id, 'membership_id' => $membership->id,
            'scope_type' => 'tenant', 'scope_id' => null, 'granted_by' => $grantedBy,
        ]);
        $membership->status = 'active';
        $membership->save();
        return $membership;
    }

    private function isSafeLocalDatabase(): bool
    {
        if (!app()->environment('local')) return false;
        $connection = DB::connection();
        return $connection->getDriverName() === 'mysql'
            && $connection->getDatabaseName() === 'hanasi_hris_dev'
            && in_array((string) config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
