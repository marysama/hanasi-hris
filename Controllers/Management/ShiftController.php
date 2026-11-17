<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ScheduleRepository;
use App\Services\AuditLogger;
use App\Core\Database;

class ShiftController
{
    public function index(): void
    {
        $repo = new ScheduleRepository();
        $shifts = $repo->getAllShifts();
        View::render('management/shifts', ['shifts' => $shifts], 'management');
    }

    public function store(): void
    {
        $name = trim(Request::post('name'));
        $start = Request::post('start_time');
        $end = Request::post('end_time');
        $is_night = Request::post('is_night_shift') ? 1 : 0;
        $tolerance = (int)Request::post('tolerance_minutes');

        if ($name && $start && $end) {
            $stmt = Database::pdo()->prepare("
                INSERT INTO shifts (name, start_time, end_time, is_night_shift, tolerance_minutes) 
                VALUES (:name, :start, :end, :night, :tol)
            ");
            $stmt->execute([
                'name' => $name, 'start' => $start, 'end' => $end, 
                'night' => $is_night, 'tol' => $tolerance
            ]);
            AuditLogger::log('CREATE_SHIFT', "Membuat shift baru: $name");
            Session::flash('success', 'Shift baru berhasil dibuat.');
        } else {
            Session::flash('error', 'Semua kolom wajib diisi.');
        }

        Response::redirect('?portal=manajemen&page=shifts');
    }
}