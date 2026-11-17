<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Database;
use PDO;
use App\Repositories\EmployeeRepository;

class TaskController
{
    public function index(): void
    {
        $pdo = Database::pdo();
        // Ambil semua tugas beserta nama karyawan
        $stmt = $pdo->query("
            SELECT t.*, e.full_name 
            FROM tasks t 
            JOIN employees e ON t.employee_id = e.id 
            ORDER BY t.id DESC
        ");
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $empRepo = new EmployeeRepository();
        $employees = $empRepo->getAll();

        View::render('management/tasks', ['tasks' => $tasks, 'employees' => $employees], 'management');
    }

    public function store(): void
    {
        $empId = (int)Request::post('employee_id');
        $title = trim(Request::post('title'));
        $site = trim(Request::post('site'));

        if ($empId && $title && $site) {
            $stmt = Database::pdo()->prepare("INSERT INTO tasks (employee_id, title, site) VALUES (:emp, :title, :site)");
            $stmt->execute(['emp' => $empId, 'title' => $title, 'site' => $site]);
            Session::flash('success', 'Tugas baru berhasil ditambahkan.');
        } else {
            Session::flash('error', 'Semua kolom wajib diisi.');
        }

        Response::redirect('?portal=manajemen&page=tasks');
    }
}