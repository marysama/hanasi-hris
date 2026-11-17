<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ShiftSwapRepository;
use App\Core\Database;
use PDO;

class LeaderApprovalController
{
    // ... (shiftSwaps & approveShiftSwap)

    public function editAttendance(): void
    {
        $user = Session::get('user');
        $empId = (int)Request::get('emp_id');
        $date = Request::get('date', date('Y-m-d'));

        $stmt = Database::pdo()->prepare("SELECT id, full_name FROM employees WHERE id = :emp AND supervisor_id = :sup LIMIT 1");
        $stmt->execute(['emp' => $empId, 'sup' => $user['id']]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            Session::flash('error', 'Akses ditolak.');
            Response::redirect('?portal=karyawan&page=leader_dashboard');
        }

        $stmtAtt = Database::pdo()->prepare("SELECT * FROM attendance_records WHERE employee_id = :emp AND work_date = :date LIMIT 1");
        $stmtAtt->execute(['emp' => $empId, 'date' => $date]);
        $attendance = $stmtAtt->fetch(PDO::FETCH_ASSOC);

        View::render('employee/leader_edit_attendance', [
            'employee' => $employee,
            'date' => $date,
            'attendance' => $attendance
        ], 'employee');
    }

    public function saveAttendance(): void
    {
        $user = Session::get('user');
        $empId = (int)Request::post('emp_id');
        $date = Request::post('date');
        $status = Request::post('status'); // hadir, sakit, izin, alfa
        $checkIn = Request::post('check_in_at');
        $checkOut = Request::post('check_out_at');

        $stmt = Database::pdo()->prepare("SELECT id FROM employees WHERE id = :emp AND supervisor_id = :sup LIMIT 1");
        $stmt->execute(['emp' => $empId, 'sup' => $user['id']]);
        if (!$stmt->fetchColumn()) {
            Response::abort(403, 'Akses Ditolak.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // 1. Simpan ke attendance_records
            $stmtCheck = $pdo->prepare("SELECT id FROM attendance_records WHERE employee_id = :emp AND work_date = :date LIMIT 1");
            $stmtCheck->execute(['emp' => $empId, 'date' => $date]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUpd = $pdo->prepare("UPDATE attendance_records SET status = :status, check_in_at = :in, check_out_at = :out WHERE id = :id");
                $stmtUpd->execute(['status' => $status, 'in' => $checkIn ?: null, 'out' => $checkOut ?: null, 'id' => $existingId]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO attendance_records (employee_id, work_date, status, check_in_at, check_out_at) VALUES (:emp, :date, :status, :in, :out)");
                $stmtIns->execute(['emp' => $empId, 'date' => $date, 'status' => $status, 'in' => $checkIn ?: null, 'out' => $checkOut ?: null]);
            }

            // 2. Jika status Izin/Sakit, buat record di leave_requests (agar muncul di Persetujuan & Payroll)
            if ($status === 'izin' || $status === 'sakit') {
                // Hapus dulu jika ada pengajuan lain di tanggal yang sama (biar ga double)
                $pdo->prepare("DELETE FROM leave_requests WHERE employee_id = :emp AND leave_date = :date")->execute(['emp' => $empId, 'date' => $date]);
                
                $stmtLeave = $pdo->prepare("
                    INSERT INTO leave_requests (employee_id, type, leave_date, reason, status, approved_by) 
                    VALUES (:emp, :type, :date, 'Diinput oleh Atasan', 'disetujui', :admin)
                ");
                $stmtLeave->execute([
                    'emp' => $empId, 
                    'type' => $status, 
                    'date' => $date,
                    'admin' => $user['id']
                ]);
            }

            $pdo->commit();
            Session::flash('success', 'Absensi bawahan berhasil diperbarui dan disinkronkan.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            Session::flash('error', 'Gagal: ' . $e->getMessage());
        }
        Response::redirect('?portal=karyawan&page=leader_dashboard');
    }
}