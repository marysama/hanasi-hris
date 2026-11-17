<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ShiftSwapRepository;
use App\Services\Notifier;
use App\Core\Database;

class ShiftSwapController
{
    public function index(): void
    {
        $user = Session::get('user');
        $repo = new ShiftSwapRepository();
        $requests = $repo->getByEmployee((int)$user['id']);
        View::render('employee/shift_swap', ['requests' => $requests], 'employee');
    }

    public function store(): void
    {
        $user = Session::get('user');
        $orig = Request::post('original_date');
        $new = Request::post('new_date');
        $reason = trim(Request::post('reason'));

        if ($orig && $new && $reason) {
            $repo = new ShiftSwapRepository();
            $repo->create([
                'employee_id' => (int)$user['id'],
                'original_date' => $orig,
                'new_date' => $new,
                'reason' => $reason
            ]);
            Session::flash('success', 'Pengajuan pindah libur berhasil dikirim.');
        } else {
            Session::flash('error', 'Semua kolom wajib diisi.');
        }
        Response::redirect('?portal=karyawan&page=shift_swap');
    }
}