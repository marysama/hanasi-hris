<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\SettingService;

class SettingController
{
    public function index(): void
    {
        $service = new SettingService();
        $settings = [
            'lat' => $service->get('office_lat'),
            'lng' => $service->get('office_lng'),
            'radius' => $service->get('office_radius'),
            'sick_mobile' => $service->get('sick_deduction_percent_mobile', 50),
            'cutoff' => $service->get('attendance_cutoff_date', date('Y-m-d')),
            'payroll_cycle' => $service->get('payroll_cycle', '26-25'),
        ];
        View::render('management/settings', ['settings' => $settings], 'management');
    }

    public function update(): void
    {
        $service = new SettingService();
        $service->set('office_lat', Request::post('office_lat'));
        $service->set('office_lng', Request::post('office_lng'));
        $service->set('office_radius', Request::post('office_radius'));
        $service->set('sick_deduction_percent_mobile', Request::post('sick_deduction_percent_mobile', 50));
        $service->set('attendance_cutoff_date', Request::post('attendance_cutoff_date'));
        $service->set('payroll_cycle', Request::post('payroll_cycle'));

        Session::flash('success', 'Pengaturan berhasil diperbarui.');
        Response::redirect('?portal=manajemen&page=settings');
    }
}