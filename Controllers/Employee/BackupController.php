<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\BackupRepository;
use App\Core\Database;
use PDO;

class BackupController
{
    public function index(): void
    {
        $user = Session::get('user');
        $repo = new BackupRepository();
        $backups = $repo->getByEmployee((int)$user['id']);
        View::render('employee/backup', ['backups' => $backups], 'employee');
    }

    public function store(): void
    {
        $user = Session::get('user');
        $date = Request::post('work_date');
        $area = trim(Request::post('area_name'));

        if ($date && $area) {
            $repo = new BackupRepository();
            $repo->create([
                'employee_id' => (int)$user['id'],
                'work_date' => $date,
                'area_name' => $area
            ]);
            
            // Kirim Notifikasi ke Atasan (FIX: SELECT s.id, bukan s.user_id)
            $stmt = Database::pdo()->prepare("
                SELECT s.id 
                FROM employees e 
                JOIN user_employee_links l ON e.supervisor_id = l.employee_id 
                JOIN users s ON l.user_id = s.id
                WHERE e.id = (SELECT employee_id FROM user_employee_links WHERE user_id = :uid LIMIT 1)
            ");
            $stmt->execute(['uid' => $user['id']]);
            $supervisorUserId = $stmt->fetchColumn();

            if ($supervisorUserId) {
                \App\Services\Notifier::send(
                    (int)$supervisorUserId, 
                    'Laporan Backup Baru', 
                    "Karyawan " . $user['full_name'] . " melaporkan backup di Area: " . $area . " pada tanggal " . date('d M Y', strtotime($date)) . ". Insentif Rp 80.000 telah dicatat."
                );
            }

            Session::flash('success', 'Laporan backup berhasil disimpan.');
        } else {
            Session::flash('error', 'Tanggal dan area wajib diisi.');
        }
        Response::redirect('?portal=karyawan&page=backup');
    }
}