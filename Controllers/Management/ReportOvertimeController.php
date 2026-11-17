<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;

class ReportOvertimeController
{
    public function index(): void
    {
        $period = Request::get('month', date('Y-m'));
        
        // Query untuk mengambil data lembur beserta tarif gaji pokok karyawan
        $stmt = Database::pdo()->prepare("
            SELECT o.*, e.full_name, s.basic_salary, s.overtime_rate 
            FROM overtime_requests o
            JOIN employees e ON o.employee_id = e.id
            LEFT JOIN employee_salaries s ON e.id = s.employee_id
            WHERE o.status = 'disetujui' AND o.work_date LIKE :period
            ORDER BY o.work_date DESC
        ");
        $stmt->execute(['period' => "$period-%"]);
        $rawOvertimes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Proses perhitungan nominal
        $overtimes = [];
        foreach ($rawOvertimes as $o) {
            // Jika tarif lembur di database 0, hitung otomatis (Gaji Pokok / 26 / 8)
            $rate = (float)$o['overtime_rate'];
            if ($rate <= 0) {
                $rate = (float)$o['basic_salary'] / 26 / 8;
            }
            $totalPay = $o['duration_hours'] * $rate;
            
            $o['calc_rate'] = $rate;
            $o['total_pay'] = $totalPay;
            $overtimes[] = $o;
        }

        View::render('management/report_overtime', [
            'overtimes' => $overtimes, 
            'period' => $period
        ], 'management');
    }

    public function delete(): void
    {
        $id = (int)Request::post('id');
        $stmt = Database::pdo()->prepare("DELETE FROM overtime_requests WHERE id = :id");
        $stmt->execute(['id' => $id]);
        
        Response::redirect('?portal=manajemen&page=report_overtime');
    }
}