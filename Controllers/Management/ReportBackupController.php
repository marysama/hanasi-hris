<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BackupRepository;

class ReportBackupController
{
    public function index(): void
    {
        $period = Request::get('month', date('Y-m'));
        $repo = new BackupRepository();
        $backups = $repo->getAllByPeriod($period);

        View::render('management/report_backup', ['backups' => $backups, 'period' => $period], 'management');
    }

    public function delete(): void
    {
        $id = (int)Request::post('id');
        $repo = new BackupRepository();
        $repo->delete($id);
        
        Response::redirect('?portal=manajemen&page=report_backup');
    }
}