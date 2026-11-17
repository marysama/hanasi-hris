<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Session;
use App\Core\Database;
use PDO; // <--- Tambahan ini yang tadi kelupaan

class DashboardController
{
    public function index(): void
    {
        $user = Session::get('user');
        $pdo = Database::pdo();

        // 1. Hitung Total Karyawan
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE status = 'active'");
        $totalEmployees = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // 2. Hitung Karyawan Hadir Hari Ini
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM attendance_records WHERE work_date = CURDATE()");
        $totalPresent = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // 3. Hitung Pengajuan Cuti Pending
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM leave_requests WHERE status = 'menunggu'");
        $pendingLeaves = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        View::render('management/dashboard', [
            'user' => $user,
            'stats' => [
                'employees' => $totalEmployees,
                'present' => $totalPresent,
                'pending_leaves' => $pendingLeaves
            ]
        ], 'management');
    }
}