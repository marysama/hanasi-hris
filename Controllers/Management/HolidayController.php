<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Database;
use App\Services\AuditLogger;
use PDO;

class HolidayController
{
    public function index(): void
    {
        $stmt = Database::pdo()->query("SELECT * FROM holidays ORDER BY holiday_date ASC");
        $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
        View::render('management/holidays', ['holidays' => $holidays], 'management');
    }

    public function store(): void
    {
        $date = Request::post('holiday_date');
        $name = trim(Request::post('name'));

        if ($date && $name) {
            $stmt = Database::pdo()->prepare("INSERT IGNORE INTO holidays (holiday_date, name) VALUES (:date, :name)");
            $stmt->execute(['date' => $date, 'name' => $name]);
            AuditLogger::log('CREATE_HOLIDAY', "Menambahkan hari libur: $name ($date)");
            Session::flash('success', 'Hari libur berhasil ditambahkan.');
        } else {
            Session::flash('error', 'Tanggal dan nama libur wajib diisi.');
        }
        Response::redirect('?portal=manajemen&page=holidays');
    }

    public function delete(): void
    {
        $id = (int)Request::post('id');
        if ($id) {
            $stmt = Database::pdo()->prepare("DELETE FROM holidays WHERE id = :id");
            $stmt->execute(['id' => $id]);
            Session::flash('success', 'Hari libur berhasil dihapus.');
        }
        Response::redirect('?portal=manajemen&page=holidays');
    }
}