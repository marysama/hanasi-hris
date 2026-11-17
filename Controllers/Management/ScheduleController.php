<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ScheduleRepository;
use App\Repositories\EmployeeRepository;

class ScheduleController
{
    public function index(): void
    {
        $repo = new ScheduleRepository();
        $empRepo = new EmployeeRepository();
        
        $shifts = $repo->getAllShifts();
        $employees = $empRepo->getAll();

        // Kelompokkan karyawan berdasarkan Role
        $groupedEmployees = [];
        foreach($employees as $e) {
            $role = $e['role_name'] ?? 'Tanpa Role';
            $groupedEmployees[$role][] = $e;
        }

        View::render('management/schedule', [
            'shifts' => $shifts,
            'groupedEmployees' => $groupedEmployees
        ], 'management');
    }

    public function store(): void
    {
        $empId = (int)Request::post('employee_id');
        $shiftId = (int)Request::post('shift_id');
        $date = Request::post('work_date');

        if ($empId && $shiftId && $date) {
            $repo = new ScheduleRepository();
            $repo->assignShift($empId, $shiftId, $date);
            Session::flash('success', 'Jadwal berhasil ditambahkan/diperbarui.');
        } else {
            Session::flash('error', 'Semua kolom wajib diisi.');
        }

        Response::redirect('?portal=manajemen&page=schedule');
    }
}