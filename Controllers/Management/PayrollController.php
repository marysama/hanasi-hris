<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\PayrollRepository;
use App\Repositories\EmployeeRepository;
use App\Services\AuditLogger;

class PayrollController
{
    public function index(): void
    {
        $period = Request::get('period', date('Y-m'));
        $repo = new PayrollRepository();
        $empRepo = new EmployeeRepository();

        $payrolls = $repo->getAllPayrolls($period);
        $employees = $empRepo->getAll();

        $salaries = [];
        foreach($employees as $e) {
            $salaries[$e['id']] = $repo->getSalaryComponents((int)$e['id']);
        }

        View::render('management/payroll', [
            'period' => $period,
            'payrolls' => $payrolls,
            'employees' => $employees,
            'salaries' => $salaries
        ], 'management');
    }

    public function setSalary(): void
    {
        $empId = (int)Request::post('employee_id');
        
        $repo = new PayrollRepository();
        $repo->setSalaryComponents($empId, [
            'basic_salary' => (float)Request::post('basic_salary'),
            'allowance_position' => (float)Request::post('allowance_position'),
            'overtime_rate' => (float)Request::post('overtime_rate'),
            'deduction_bpjs_tk' => (float)Request::post('deduction_bpjs_tk'),
            'deduction_bpjs_kes' => (float)Request::post('deduction_bpjs_kes'),
        ]);

        AuditLogger::log('SET_SALARY', "Mengatur komponen gaji karyawan ID $empId");
        Session::flash('success', 'Komponen gaji berhasil diperbarui.');
        Response::redirect('?portal=manajemen&page=payroll');
    }

    public function generate(): void
    {
        $period = Request::post('period');
        if(!$period) {
            Session::flash('error', 'Pilih periode terlebih dahulu.');
            Response::redirect('?portal=manajemen&page=payroll');
        }

        $repo = new PayrollRepository();
        $count = $repo->generatePayrollForAll($period);

        AuditLogger::log('GENERATE_PAYROLL', "Generate payroll periode $period untuk $count karyawan");
        Session::flash('success', "Berhasil generate payroll otomatis untuk $count karyawan di periode $period.");
        Response::redirect('?portal=manajemen&page=payroll&period=' . $period);
    }
}