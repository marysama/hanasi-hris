<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Database;
use PDO;

class ApprovalController
{
    public function index(): void
    {
        $user = Session::get('user');
        $empId = $user['id']; // asumsi user id == employee id? Tidak, kita harus cari employee_id
        // Cari employee_id dari user
        $stmtEmp = Database::pdo()->prepare("SELECT employee_id FROM user_employee_links WHERE user_id = :uid LIMIT 1");
        $stmtEmp->execute(['uid' => $user['id']]);
        $employeeId = $stmtEmp->fetchColumn();

        if (!$employeeId) {
            Response::abort(403, 'Akses Ditolak: Anda bukan karyawan aktif.');
        }

        // Ambil pengajuan dari bawahan (yang supervisor_id-nya adalah employeeId ini)
        // 1. Cuti/Izin
        $stmtCuti = Database::pdo()->prepare("
            SELECT l.id, l.type, l.leave_date, l.reason, l.attachment_path, e.full_name 
            FROM leave_requests l
            JOIN employees e ON l.employee_id = e.id
            WHERE e.supervisor_id = :sup_id AND l.status = 'menunggu'
            ORDER BY l.created_at ASC
        ");
        $stmtCuti->execute(['sup_id' => $employeeId]);
        $leaves = $stmtCuti->fetchAll(PDO::FETCH_ASSOC);

        // 2. Lembur
        $stmtLembur = Database::pdo()->prepare("
            SELECT o.id, o.work_date, o.duration_hours, o.reason, e.full_name 
            FROM overtime_requests o
            JOIN employees e ON o.employee_id = e.id
            WHERE e.supervisor_id = :sup_id AND o.status = 'menunggu'
            ORDER BY o.created_at ASC
        ");
        $stmtLembur->execute(['sup_id' => $employeeId]);
        $overtimes = $stmtLembur->fetchAll(PDO::FETCH_ASSOC);

        View::render('employee/approval', [
            'leaves' => $leaves,
            'overtimes' => $overtimes
        ], 'employee');
    }

    public function approveLeave(): void
    {
        $id = (int)Request::post('id');
        $status = Request::post('status'); // disetujui / ditolak
        $user = Session::get('user');

        $stmt = Database::pdo()->prepare("UPDATE leave_requests SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $id]);

        // Notif ke karyawan
        $stmtEmp = Database::pdo()->prepare("SELECT employee_id FROM leave_requests WHERE id = :id");
        $stmtEmp->execute(['id' => $id]);
        $empId = $stmtEmp->fetchColumn();
        
        if ($empId) {
            $stmtUser = Database::pdo()->prepare("SELECT user_id FROM user_employee_links WHERE employee_id = :eid");
            $stmtUser->execute(['eid' => $empId]);
            $userId = $stmtUser->fetchColumn();
            if ($userId) {
                \App\Services\Notifier::send((int)$userId, 'Status Pengajuan', "Pengajuan izin/cuti Anda telah di-$status oleh Atasan.");
            }
        }

        Session::flash('success', 'Pengajuan berhasil di-' . $status);
        Response::redirect('?portal=karyawan&page=approval');
    }

    public function approveOvertime(): void
    {
        $id = (int)Request::post('id');
        $status = Request::post('status'); // disetujui / ditolak
        $user = Session::get('user');

        $stmt = Database::pdo()->prepare("UPDATE overtime_requests SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $id]);

        // Notif ke karyawan
        $stmtEmp = Database::pdo()->prepare("SELECT employee_id FROM overtime_requests WHERE id = :id");
        $stmtEmp->execute(['id' => $id]);
        $empId = $stmtEmp->fetchColumn();
        
        if ($empId) {
            $stmtUser = Database::pdo()->prepare("SELECT user_id FROM user_employee_links WHERE employee_id = :eid");
            $stmtUser->execute(['eid' => $empId]);
            $userId = $stmtUser->fetchColumn();
            if ($userId) {
                \App\Services\Notifier::send((int)$userId, 'Status Lembur', "Pengajuan lembur Anda telah di-$status oleh Atasan.");
            }
        }

        Session::flash('success', 'Lembur berhasil di-' . $status);
        Response::redirect('?portal=karyawan&page=approval');
    }
}