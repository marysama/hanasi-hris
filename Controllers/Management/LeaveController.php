<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\LeaveRepository;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Core\Database;
use PDO;

class LeaveController
{
    public function index(): void
    {
        $repo = new LeaveRepository();
        $leaves = $repo->getAll();
        View::render('management/leaves', ['leaves' => $leaves], 'management');
    }

    public function approve(): void
    {
        $id = (int)Request::post('id');
        $status = Request::post('status');
        $user = Session::get('user');

        $repo = new LeaveRepository();
        $repo->approve($id, (int)$user['id'], $status);

        // ... (Notif ke karyawan)

        Session::flash('success', "Pengajuan berhasil di-$status.");
        Response::redirect('?portal=manajemen&page=leaves');
    }

    public function updateSurat(): void
    {
        $id = (int)Request::post('id');
        $newType = Request::post('new_type'); // 'sakit' (tanpa surat) atau 'sakit_surat' (dengan surat)
        $user = Session::get('user');

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $attachmentName = null;

            // Upload file jika tipe baru adalah 'sakit_surat'
            if ($newType === 'sakit_surat' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../../public/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $attachmentName = 'surat_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $attachmentName);
            }

            // Update tipe dan attachment
            $stmt = $pdo->prepare("UPDATE leave_requests SET type = :type, attachment_path = :file WHERE id = :id");
            $stmt->execute([
                'type' => $newType === 'sakit_surat' ? 'sakit' : 'sakit', // Tetap 'sakit' di DB, bedanya di attachment
                'file' => $attachmentName,
                'id' => $id
            ]);

            // Notif ke Karyawan
            $stmtEmp = $pdo->prepare("SELECT employee_id FROM leave_requests WHERE id = :id");
            $stmtEmp->execute(['id' => $id]);
            $empId = $stmtEmp->fetchColumn();
            if ($empId) {
                $stmtUser = $pdo->prepare("SELECT user_id FROM user_employee_links WHERE employee_id = :eid");
                $stmtUser->execute(['eid' => $empId]);
                $userId = $stmtUser->fetchColumn();
                if ($userId) {
                    Notifier::send((int)$userId, 'Update Surat Sakit', "Surat dokter untuk pengajuan sakit Anda telah diperbarui oleh HR.");
                }
            }

            $pdo->commit();
            Session::flash('success', 'Status surat sakit berhasil diperbarui.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            Session::flash('error', 'Gagal: ' . $e->getMessage());
        }
        Response::redirect('?portal=manajemen&page=leaves');
    }
}