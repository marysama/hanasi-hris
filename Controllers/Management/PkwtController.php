<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Database;
use App\Services\Notifier;
use PDO;

class PkwtController
{
    public function index(): void
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM master_documents WHERE doc_type = 'PKWT' LIMIT 1");
        $stmt->execute();
        $master = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmtEmp = Database::pdo()->query("
            SELECT e.id, e.nik, e.full_name, a.name as area_name, d.id as doc_id, d.file_path, d.uploaded_at, d.status_review, d.rejection_reason
            FROM employees e
            LEFT JOIN work_areas a ON e.area_id = a.id
            LEFT JOIN employee_documents d ON e.id = d.employee_id AND d.doc_type = 'PKWT'
            WHERE e.employment_status = 'aktif'
            ORDER BY e.full_name ASC
        ");
        $allEmployees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

        $menunggu = []; $disetujui = []; $ditolak = []; $belum = [];
        foreach ($allEmployees as $e) {
            if (!$e['file_path']) $belum[] = $e;
            elseif ($e['status_review'] === 'menunggu') $menunggu[] = $e;
            elseif ($e['status_review'] === 'disetujui') $disetujui[] = $e;
            elseif ($e['status_review'] === 'ditolak') $ditolak[] = $e;
        }

        View::render('management/pkwt', [
            'master' => $master,
            'belum' => $belum,
            'menunggu' => $menunggu,
            'disetujui' => $disetujui,
            'ditolak' => $ditolak
        ], 'management');
    }

    public function approve(): void
    {
        $id = (int)Request::post('doc_id');
        $status = Request::post('status'); 
        $reason = Request::post('reason');

        $stmt = Database::pdo()->prepare("UPDATE employee_documents SET status_review = :status, rejection_reason = :reason WHERE id = :id");
        $stmt->execute(['status' => $status, 'reason' => $reason, 'id' => $id]);

        $stmtDoc = Database::pdo()->prepare("SELECT employee_id FROM employee_documents WHERE id = :id");
        $stmtDoc->execute(['id' => $id]);
        $empId = $stmtDoc->fetchColumn();
        
        if ($empId) {
            $stmtUser = Database::pdo()->prepare("SELECT user_id FROM user_employee_links WHERE employee_id = :eid");
            $stmtUser->execute(['eid' => $empId]);
            $userId = $stmtUser->fetchColumn();
            if ($userId) {
                $msg = $status === 'disetujui' ? 'PKWT Anda telah disetujui.' : 'PKWT Anda ditolak. Alasan: ' . $reason;
                Notifier::send((int)$userId, 'Status PKWT', $msg);
            }
        }

        Session::flash('success', "PKWT berhasil di-$status.");
        Response::redirect('?portal=manajemen&page=pkwt');
    }

    public function remind(): void
    {
        $empId = (int)Request::post('emp_id');
        $stmt = Database::pdo()->prepare("SELECT user_id FROM user_employee_links WHERE employee_id = :eid");
        $stmt->execute(['eid' => $empId]);
        $userId = $stmt->fetchColumn();
        
        if ($userId) {
            Notifier::send((int)$userId, 'Pengingat PKWT', 'Segera isi dan unggah dokumen PKWT Anda di menu Profil.');
            Session::flash('success', 'Notifikasi pengingat berhasil dikirim.');
        }
        Response::redirect('?portal=manajemen&page=pkwt');
    }
}