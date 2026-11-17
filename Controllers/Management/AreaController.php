<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\AreaRepository;
use App\Services\AuditLogger;

class AreaController
{
    public function index(): void
    {
        $repo = new AreaRepository();
        $areas = $repo->getAll();
        View::render('management/areas', ['areas' => $areas], 'management');
    }

    public function store(): void
    {
        $data = [
            'name' => trim(Request::post('name')),
            'latitude' => Request::post('latitude'),
            'longitude' => Request::post('longitude'),
            'radius_m' => (int)Request::post('radius_m', 100),
            'sick_deduction_percent' => (int)Request::post('sick_deduction_percent', 50)
        ];

        if ($data['name'] && $data['latitude'] && $data['longitude']) {
            $repo = new AreaRepository();
            $repo->create($data);
            AuditLogger::log('CREATE_AREA', "Menambahkan area kerja: {$data['name']}");
            Session::flash('success', 'Area kerja berhasil ditambahkan.');
        } else {
            Session::flash('error', 'Semua kolom wajib diisi.');
        }
        Response::redirect('?portal=manajemen&page=areas');
    }

    public function update(): void
    {
        $id = (int)Request::post('id');
        $data = [
            'name' => trim(Request::post('name')),
            'latitude' => Request::post('latitude'),
            'longitude' => Request::post('longitude'),
            'radius_m' => (int)Request::post('radius_m'),
            'sick_deduction_percent' => (int)Request::post('sick_deduction_percent', 50)
        ];
        
        if ($id && $data['name']) {
            $repo = new AreaRepository();
            $repo->update($id, $data);
            Session::flash('success', 'Area kerja berhasil diperbarui.');
        }
        Response::redirect('?portal=manajemen&page=areas');
    }

    public function delete(): void
    {
        $id = (int)Request::post('id');
        if ($id) {
            $repo = new AreaRepository();
            $repo->delete($id);
            Session::flash('success', 'Area kerja berhasil dihapus.');
        }
        Response::redirect('?portal=manajemen&page=areas');
    }
}