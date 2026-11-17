<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Database;
use PDO;

class AttendanceController
{
    public function index(): void
    {
        $month = Request::get('month', date('Y-m'));
        $searchName = Request::get('search_name', '');
        $searchArea = Request::get('search_area', '');
        $searchDate = Request::get('search_date', '');

        // Build Query
        $query = "
            SELECT a.*, e.full_name, e.nik, w.name as area_name
            FROM attendance_records a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN work_areas w ON e.area_id = w.id
            WHERE a.work_date LIKE :month
        ";
        
        $params = ['month' => "$month-%"];

        if (!empty($searchName)) {
            $query .= " AND (e.full_name LIKE :name OR e.nik LIKE :nik)";
            $params['name'] = "%$searchName%";
            $params['nik'] = "%$searchName%";
        }

        if (!empty($searchArea)) {
            $query .= " AND w.name LIKE :area";
            $params['area'] = "%$searchArea%";
        }

        if (!empty($searchDate)) {
            $query .= " AND a.work_date = :date";
            $params['date'] = $searchDate;
        }

        $query .= " ORDER BY a.work_date DESC, a.check_in_at DESC";

        $stmt = Database::pdo()->prepare($query);
        $stmt->execute($params);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil list area untuk dropdown
        $areas = Database::pdo()->query("SELECT name FROM work_areas ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

        View::render('management/attendance', [
            'attendance' => $attendance,
            'month' => $month,
            'searchName' => $searchName,
            'searchArea' => $searchArea,
            'searchDate' => $searchDate,
            'areas' => $areas
        ], 'management');
    }
}