<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Session;
use App\Repositories\AttendanceRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\NewsRepository;
use App\Repositories\EmployeeRepository;

class DashboardController
{
    public function index(): void
    {
        $user = Session::get('user');
        $attRepo = new AttendanceRepository();
        $schRepo = new ScheduleRepository();
        $newsRepo = new NewsRepository();
        $empRepo = new EmployeeRepository();
        
        $today = $attRepo->getTodayAttendance((int)$user['id']);
        $history = $attRepo->getHistory((int)$user['id'], 3);
        $shift = $schRepo->getTodayShift((int)$user['id']);
        $latestNews = $newsRepo->getLatest(1);
        $profile = $empRepo->getProfileByUserId((int)$user['id']);

        View::render('employee/dashboard', [
            'user' => $user,
            'today' => $today,
            'history' => $history,
            'shift' => $shift,
            'latestNews' => $latestNews[0] ?? null,
            'profile' => $profile
        ], 'employee');
    }
}