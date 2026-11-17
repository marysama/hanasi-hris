<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\EmployeeService;
use App\Repositories\RoleRepository;
use App\Repositories\AreaRepository;
use App\Repositories\EmployeeRepository;
use App\Services\AuditLogger;

class EmployeeController
{
    public function index(): void
    {
        $service = new EmployeeService();
        $employees = $service->getAll();
        View::render('management/employees/index', ['employees' => $employees], 'management');
    }

    public function create(): void
    {
        $roleRepo = new RoleRepository();
        $areaRepo = new AreaRepository();
        $empRepo = new EmployeeRepository();
        
        $roles = $roleRepo->getAllRoles();
        $areas = $areaRepo->getAll();
        $supervisors = $empRepo->getAll();

        View::render('management/employees/create', [
            'roles' => $roles, 
            'areas' => $areas,
            'supervisors' => $supervisors
        ], 'management');
    }

    public function store(): void
    {
        try {
            $offDays = Request::post('weekly_off_days', []);
            $weeklyOffStr = is_array($offDays) ? implode(',', $offDays) : null;

            $service = new EmployeeService();
            $service->create([
                'nik' => trim(Request::post('nik')),
                'full_name' => trim(Request::post('full_name')),
                'join_date' => Request::post('join_date'),
                'username' => trim(Request::post('username')),
                'password' => Request::post('password'),
                'role_id' => Request::post('role_id'),
                'area_id' => Request::post('area_id'),
                'supervisor_id' => Request::post('supervisor_id'),
                'is_mobile' => Request::post('is_mobile') ? 1 : 0,
                'is_shift_required' => Request::post('is_shift_required') ? 1 : 0,
                'weekly_off_day' => $weeklyOffStr,
                'payroll_cycle' => Request::post('payroll_cycle', '26-25'),
                'basic_salary' => (float)Request::post('basic_salary'),
                'allowance_position' => (float)Request::post('allowance_position'),
                'deduction_bpjs_tk' => (float)Request::post('deduction_bpjs_tk'),
                'deduction_bpjs_kes' => (float)Request::post('deduction_bpjs_kes'),
                'is_overtime_eligible' => Request::post('is_overtime_eligible') ? 1 : 0,
            ]);
            Session::flash('success', 'Karyawan berhasil ditambahkan.');
            Response::redirect('?portal=manajemen&page=employees');
        } catch (\Exception $e) {
            Session::flash('error', 'Gagal: ' . $e->getMessage());
            Response::redirect('?portal=manajemen&page=employees_create');
        }
    }

    public function update(): void
    {
        $id = (int)Request::post('id');
        $repo = new EmployeeRepository();
        
        try {
            $offDays = Request::post('weekly_off_days', []);
            $weeklyOffStr = is_array($offDays) ? implode(',', $offDays) : null;

            $repo->update($id, [
                'nik' => trim(Request::post('nik')),
                'full_name' => trim(Request::post('full_name')),
                'join_date' => Request::post('join_date'),
                'username' => trim(Request::post('username')),
                'area_id' => Request::post('area_id'),
                'supervisor_id' => Request::post('supervisor_id'),
                'is_mobile' => Request::post('is_mobile') ? 1 : 0,
                'is_shift_required' => Request::post('is_shift_required') ? 1 : 0,
                'no_kpj' => trim(Request::post('no_kpj')),
                'no_bpjs_kes' => trim(Request::post('no_bpjs_kes')),
                'weekly_off_day' => $weeklyOffStr,
                'payroll_cycle' => Request::post('payroll_cycle', '26-25'),
                'basic_salary' => (float)Request::post('basic_salary'),
                'allowance_position' => (float)Request::post('allowance_position'),
                'deduction_bpjs_tk' => (float)Request::post('deduction_bpjs_tk'),
                'deduction_bpjs_kes' => (float)Request::post('deduction_bpjs_kes'),
                'is_overtime_eligible' => Request::post('is_overtime_eligible') ? 1 : 0,
                'role_id' => (int)Request::post('role_id'),
                'employment_status' => Request::post('employment_status') 
            ]);
            Session::flash('success', 'Data karyawan berhasil diperbarui.');
        } catch (\Exception $e) {
            Session::flash('error', 'Gagal: ' . $e->getMessage());
        }
        Response::redirect('?portal=manajemen&page=employees');
    }

    public function updateStatus(): void
    {
        $id = (int)Request::post('id');
        $status = Request::post('status');
        
        $repo = new EmployeeRepository();
        $repo->updateStatus($id, $status);
        
        Session::flash('success', 'Status karyawan berhasil diubah.');
        Response::redirect('?portal=manajemen&page=employees');
    }

    public function delete(): void
    {
        $id = (int)Request::post('id');
        $repo = new EmployeeRepository();
        $repo->delete($id);
        
        Session::flash('success', 'Karyawan berhasil dihapus permanen.');
        Response::redirect('?portal=manajemen&page=employees');
    }

    public function forceLogout(): void
    {
        $userId = (int)Request::post('user_id');
        $repo = new EmployeeRepository();
        $repo->forceLogout($userId);
        Session::flash('success', 'Sesi login karyawan berhasil diputus.');
        Response::redirect('?portal=manajemen&page=employees');
    }

    public function resetAttendance(): void
    {
        $empId = (int)Request::post('employee_id');
        $date = Request::post('work_date', date('Y-m-d'));
        
        $stmt = \App\Core\Database::pdo()->prepare("DELETE FROM attendance_records WHERE employee_id = :emp AND work_date = :date");
        $stmt->execute(['emp' => $empId, 'date' => $date]);
        
        AuditLogger::log('RESET_ATTENDANCE', "Menghapus absensi karyawan ID $empId tanggal $date");
        Session::flash('success', 'Data absensi berhasil di-reset. Karyawan dapat absen ulang.');
        Response::redirect('?portal=manajemen&page=attendance');
    }
}