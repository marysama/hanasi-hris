<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;

class ReportController
{
    public function index(): void
    {
        View::render('management/reports', [], 'management');
    }

    public function exportAttendance(): void
    {
        $start = Request::get('start', date('Y-m-01'));
        $end = Request::get('end', date('Y-m-t'));

        $stmt = Database::pdo()->prepare("
            SELECT e.nik, e.full_name, a.work_date, a.check_in_at, a.check_out_at, a.status
            FROM attendance_records a
            JOIN employees e ON a.employee_id = e.id
            WHERE a.work_date BETWEEN :start AND :end
            ORDER BY a.work_date ASC, e.full_name ASC
        ");
        $stmt->execute(['start' => $start, 'end' => $end]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->downloadCSV('Laporan_Absensi_'.$start.'_'.$end.'.csv', 
            ['NIK', 'Nama', 'Tanggal', 'Jam Masuk', 'Jam Pulang', 'Status'], 
            $data
        );
    }

    public function exportPayroll(): void
    {
        $period = Request::get('period', date('Y-m'));

        $stmt = Database::pdo()->prepare("
            SELECT e.nik, e.full_name, p.period_month, p.basic_salary, p.allowance_position, p.allowance_transport, p.allowance_meal, p.deduction_bpjs, p.deduction_tax, p.net_salary
            FROM payrolls p
            JOIN employees e ON p.employee_id = e.id
            WHERE p.period_month = :period
            ORDER BY e.full_name ASC
        ");
        $stmt->execute(['period' => $period]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->downloadCSV('Laporan_Payroll_'.$period.'.csv', 
            ['NIK', 'Nama', 'Periode', 'Gaji Pokok', 'Tunj. Jabatan', 'Tunj. Transport', 'Uang Makan', 'BPJS', 'Pajak', 'Gaji Bersih'], 
            $data
        );
    }

    private function downloadCSV($filename, $headers, $data) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}