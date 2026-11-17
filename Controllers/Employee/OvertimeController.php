<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\OvertimeRepository;
use App\Core\Database;
use PDO;

class OvertimeController
{
    public function index(): void
    {
        $user = Session::get('user');
        $repo = new OvertimeRepository();
        $overtimes = $repo->getByEmployee((int)$user['id']);
        View::render('employee/overtime', ['overtimes' => $overtimes], 'employee');
    }

    public function store(): void
    {
        $user = Session::get('user');
        $date = Request::post('work_date');
        $hours = (float)Request::post('duration_hours');
        $reason = trim(Request::post('reason'));

        if ($date && $hours > 0 && $reason) {
            $repo = new OvertimeRepository();
            $repo->create([
                'employee_id' => (int)$user['id'],
                'work_date' => $date,
                'duration_hours' => $hours,
                'reason' => $reason
            ]);
            
            // Kirim Notifikasi ke Atasan
            $stmt = Database::pdo()->prepare("
                SELECT s.id 
                FROM employees e 
                JOIN user_employee_links l ON e.supervisor_id = l.employee_id 
                JOIN users s ON l.user_id = s.id
                WHERE e.id = (SELECT employee_id FROM user_employee_links WHERE user_id = :uid LIMIT 1)
            ");
            $stmt->execute(['uid' => $user['id']]);
            $supervisorUserId = $stmt->fetchColumn();

            if ($supervisorUserId) {
                \App\Services\Notifier::send(
                    (int)$supervisorUserId, 
                    'Pengajuan Lembur Baru', 
                    "Karyawan " . $user['full_name'] . " mengajukan lembur " . $hours . " jam pada " . date('d M Y', strtotime($date)) . "."
                );
            }

            Session::flash('success', 'Pengajuan lembur berhasil dikirim.');
        } else {
            Session::flash('error', 'Data lembur tidak valid.');
        }
        Response::redirect('?portal=karyawan&page=overtime');
    }
}