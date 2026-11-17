<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\TaskRepository;

class TaskController
{
    public function index(string $type = 'semua'): void
    {
        $user = Session::get('user');
        $repo = new TaskRepository();
        $tasks = $repo->getByEmployee((int)$user['id']);

        // Filter berdasarkan halaman (Toilet / Checklist)
        if ($type === 'toilet') {
            $tasks = array_filter($tasks, fn($t) => stripos($t['title'], 'toilet') !== false);
        } elseif ($type === 'checklist') {
            $tasks = array_filter($tasks, fn($t) => stripos($t['title'], 'toilet') === false);
        }

        View::render('employee/tasks', ['tasks' => $tasks, 'type' => $type], 'employee');
    }

    public function update(): void
    {
        $id = (int)Request::post('task_id');
        $status = Request::post('status'); // belum, proses, selesai

        $repo = new TaskRepository();
        $repo->updateStatus($id, $status);

        Session::flash('success', 'Status tugas diperbarui.');
        Response::back();
    }
}