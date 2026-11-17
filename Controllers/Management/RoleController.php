<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\RoleRepository;
use App\Repositories\EmployeeRepository;
use App\Services\AuditLogger;

class RoleController
{
    public function index(): void
    {
        $roleRepo = new RoleRepository();
        $empRepo = new EmployeeRepository();
        
        $roles = $roleRepo->getAllRoles();
        $employees = $empRepo->getAll();

        View::render('management/roles', [
            'roles' => $roles,
            'employees' => $employees
        ], 'management');
    }

    public function store(): void
    {
        $name = trim(Request::post('name'));
        $level = (int)Request::post('level');

        if ($name && $level >= 1) {
            $repo = new RoleRepository();
            $repo->createRole($name, $level);
            AuditLogger::log('CREATE_ROLE', "Membuat role baru: $name (Level $level)");
            Session::flash('success', 'Role baru berhasil ditambahkan.');
        } else {
            Session::flash('error', 'Nama role dan level wajib diisi dengan benar.');
        }

        Response::redirect('?portal=manajemen&page=roles');
    }

    public function updateRole(): void
    {
        $id = (int)Request::post('role_id');
        $name = trim(Request::post('name'));
        $level = (int)Request::post('level');

        if ($id && $name && $level >= 1) {
            $repo = new RoleRepository();
            $repo->updateRole($id, $name, $level);
            AuditLogger::log('UPDATE_ROLE', "Mengubah role ID $id menjadi $name (Level $level)");
            Session::flash('success', 'Role berhasil diperbarui.');
        } else {
            Session::flash('error', 'Data tidak valid.');
        }

        Response::redirect('?portal=manajemen&page=roles');
    }

    public function deleteRole(): void
    {
        $id = (int)Request::post('role_id');
        try {
            $repo = new RoleRepository();
            $repo->deleteRole($id);
            AuditLogger::log('DELETE_ROLE', "Menghapus role ID $id");
            Session::flash('success', 'Role berhasil dihapus.');
        } catch (\Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect('?portal=manajemen&page=roles');
    }

    public function updateUser(): void
    {
        $userId = (int)Request::post('user_id');
        $roleId = (int)Request::post('role_id');

        if ($userId && $roleId) {
            $repo = new RoleRepository();
            $repo->updateUserRole($userId, $roleId);
            Session::flash('success', 'Role karyawan berhasil diperbarui.');
        } else {
            Session::flash('error', 'Data tidak lengkap.');
        }

        Response::redirect('?portal=manajemen&page=roles');
    }
}