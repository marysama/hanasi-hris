<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Session;
use App\Repositories\EmployeeRepository;
use App\Repositories\ScheduleRepository;

class MenuController
{
    public function index(): void
    {
        View::render('employee/menu', [], 'employee');
    }

    public function location(): void
    {
        $user = Session::get('user');
        $empRepo = new EmployeeRepository();
        $schRepo = new ScheduleRepository();
        
        $profile = $empRepo->getProfileByUserId((int)$user['id']);
        $shift = $schRepo->getTodayShift((int)$user['id']);

        View::render('employee/location', [
            'profile' => $profile,
            'shift' => $shift
        ], 'employee');
    }
}