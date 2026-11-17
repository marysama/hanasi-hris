<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Session;
use App\Repositories\EmployeeRepository;
use App\Core\Database;
use PDO;

class LeaderDashboardController
{
    public function index(): void
    {
        $user = Session::get('user');
        
        // 1. Cek Level User
        $stmtLvl = Database::pdo()->prepare("
            SELECT r.level FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.id 
            WHERE ur.user_id = :uid LIMIT 1
        ");
        $stmtLvl->execute(['uid' => $user['id']]);
        $level = (int)$stmtLvl->fetchColumn();

        // Jika level di bawah 2 (Karyawan biasa), tendang balik ke dashboard
        if ($level < 2) {
            Session::flash('error', 'Anda tidak memiliki akses ke menu ini.');
            \App\Core\Response::redirect('?portal=karyawan&page=dashboard');
        }

        // 2. Ambil data bawahan (Karyawan yang supervisor_id-nya adalah user ini)
        $stmt = Database::pdo()->prepare("
            SELECT e.id, e.nik, e.full_name, r.name as role_name, a.name as area_name,
                   att.check_in_at, att.check_out_at, att.status as att_status
            FROM employees e
            JOIN user_employee_links l ON e.id = l.employee_id
            LEFT JOIN user_roles ur ON l.user_id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            LEFT JOIN work_areas a ON e.area_id = a.id
            LEFT JOIN attendance_records att ON e.id = att.employee_id AND att.work_date = CURDATE()
            WHERE e.supervisor_id = :sup_id
            ORDER BY e.full_name ASC
        ");
        $stmt->execute(['sup_id' => $user['id']]);
        $subordinates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        View::render('employee/leader_dashboard', [
            'subordinates' => $subordinates,
            'level' => $level
        ], 'employee');
    }
}