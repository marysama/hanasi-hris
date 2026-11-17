<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\AttendanceRepository;
use App\Repositories\LeaveRepository;
use App\Repositories\EmployeeRepository;
use App\Core\Database;
use PDO;

class CalendarController
{
    public function index(): void
    {
        $user = Session::get('user');
        $month = Request::get('month', date('Y-m'));
        
        $attRepo = new AttendanceRepository();
        $leaveRepo = new LeaveRepository();
        $empRepo = new EmployeeRepository();

        $profile = $empRepo->getProfileByUserId((int)$user['id']);

        // Ambil Cutoff Date
        $stmtCutoff = Database::pdo()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'attendance_cutoff_date' LIMIT 1");
        $stmtCutoff->execute();
        $cutoffDate = $stmtCutoff->fetchColumn() ?: date('Y-m-d');

        $attendance = $attRepo->getMonthlyAttendance((int)$user['id'], $month);
        
        $leavesRaw = $leaveRepo->getByEmployee((int)$user['id']);
        $leaves = [];
        foreach($leavesRaw as $l) {
            if(strpos($l['leave_date'], $month) === 0 && $l['status'] === 'disetujui') {
                $leaves[$l['leave_date']] = $l['type'];
            }
        }

        $stmt = Database::pdo()->prepare("SELECT holiday_date, name FROM holidays WHERE holiday_date LIKE :month");
        $stmt->execute(['month' => "$month-%"]);
        $holidaysRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $holidays = [];
        foreach($holidaysRaw as $h) {
            $holidays[$h['holiday_date']] = $h['name'];
        }

        View::render('employee/calendar', [
            'month' => $month,
            'attendance' => $attendance,
            'leaves' => $leaves,
            'holidays' => $holidays,
            'profile' => $profile,
            'cutoffDate' => $cutoffDate // Kirim cutoff date ke view
        ], 'employee');
    }

    public function lateReport(): void
    {
        View::render('employee/late_report', [], 'employee');
    }
}