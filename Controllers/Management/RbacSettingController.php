<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\SettingService;
use App\Services\AuditLogger;
use App\Repositories\RoleRepository;

class RbacSettingController
{
    public function index(): void
    {
        $setting = new SettingService();
        $roleRepo = new RoleRepository();
        
        $roles = $roleRepo->getAllRoles();
        $settings = [
            'holiday' => $setting->get('min_level_holiday', 3),
            'employees' => $setting->get('min_level_employees', 8),
            'payroll' => $setting->get('min_level_payroll', 8),
        ];

        View::render('management/rbac_settings', ['roles' => $roles, 'settings' => $settings], 'management');
    }

    public function update(): void
    {
        $setting = new SettingService();
        $setting->set('min_level_holiday', Request::post('min_level_holiday'));
        $setting->set('min_level_employees', Request::post('min_level_employees'));
        $setting->set('min_level_payroll', Request::post('min_level_payroll'));

        AuditLogger::log('UPDATE_RBAC', "Mengubah batas level akses modul");
        Session::flash('success', 'Pengaturan Hak Akses Modul berhasil diperbarui.');
        Response::redirect('?portal=manajemen&page=rbac_settings');
    }
}