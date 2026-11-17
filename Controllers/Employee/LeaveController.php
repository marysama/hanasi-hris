<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\LeaveRepository;

class LeaveController
{
    public function index(): void
    {
        $user = Session::get('user');
        $repo = new LeaveRepository();
        $leaves = $repo->getByEmployee((int)$user['id']);
        View::render('employee/leave', ['leaves' => $leaves], 'employee');
    }

    public function store(): void
    {
        $user = Session::get('user');
        $type = Request::post('type');
        $date = Request::post('leave_date');
        $reason = trim(Request::post('reason'));
        
        $attachmentName = null;
        // Upload Surat Dokter (jika ada)
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../../public/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
            $attachmentName = 'surat_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $attachmentName);
        }

        if ($date && $reason) {
            if ($type === 'sakit' && !$attachmentName) {
                Session::flash('error', 'Untuk pengajuan Sakit, wajib melampirkan Surat Dokter.');
                Response::redirect('?portal=karyawan&page=leave');
            }

            $repo = new LeaveRepository();
            $repo->create([
                'employee_id' => (int)$user['id'],
                'type' => $type,
                'leave_date' => $date,
                'reason' => $reason,
                'attachment_path' => $attachmentName
            ]);
            Session::flash('success', 'Pengajuan berhasil dikirim.');
        } else {
            Session::flash('error', 'Tanggal dan alasan wajib diisi.');
        }
        Response::redirect('?portal=karyawan&page=leave');
    }
}