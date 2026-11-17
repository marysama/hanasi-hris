<?php
namespace App\Controllers\Auth;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;

class AuthController
{
    public function showLogin(): void
    {
        $portal = $_GET['portal'] ?? 'karyawan';
        if (Session::get('user')) {
            Response::redirect('?portal=' . urlencode($portal) . '&page=dashboard');
        }
        View::render('auth/login', ['portal' => $portal], 'auth');
    }

    public function login(): void
    {
        $username = trim((string)Request::post('username'));
        $password = (string)Request::post('password');
        $remember = Request::post('remember') ? true : false;
        $portal = Request::portal();

        if ($username === '' || $password === '') {
            Session::flash('error', 'Username dan password wajib diisi.');
            Response::redirect('?portal=' . urlencode($portal) . '&page=login');
        }

        $auth = new AuthService();
        if (!$auth->attempt($username, $password, $remember)) {
            Session::flash('error', 'Username atau password salah.');
            Response::redirect('?portal=' . urlencode($portal) . '&page=login');
        }

        $user = Session::get('user');
        $isMgmt = in_array('management_access', $user['permissions'] ?? [], true);

        if ($isMgmt && $portal === 'manajemen') {
            Response::redirect('?portal=manajemen&page=dashboard');
        } else {
            Response::redirect('?portal=karyawan&page=dashboard');
        }
    }

    public function logout(): void
    {
        $auth = new AuthService();
        $auth->logout();
        Response::redirect('?portal=karyawan&page=login');
    }
}