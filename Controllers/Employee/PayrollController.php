<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Session;
use App\Repositories\PayrollRepository;

class PayrollController
{
    public function index(): void
    {
        $user = Session::get('user');
        $repo = new PayrollRepository();
        $payrolls = $repo->getPayrollByEmployee((int)$user['id']);

        View::render('employee/payroll', ['payrolls' => $payrolls], 'employee');
    }
}