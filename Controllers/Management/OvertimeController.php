<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\OvertimeRepository;
use App\Services\AuditLogger;

class OvertimeController
{
    public function index(): void
    {
        $repo = new OvertimeRepository();
        $overtimes = $repo->getAllPending();
        View::render('management/overtime', ['overtimes' => $overtimes], 'management');
    }

    public function approve(): void
    {
        $id = (int)Request::post('id');
        $user = Session::get('user');
        
        $repo = new OvertimeRepository();
        $repo->approve($id, (int)$user['id']);
        
        AuditLogger::log('APPROVE_OVERTIME', "Menyetujui lembur ID $id");
        Session::flash('success', 'Lembur disetujui.');
        Response::redirect('?portal=manajemen&page=overtime');
    }
}