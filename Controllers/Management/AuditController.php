<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Database;
use PDO;

class AuditController
{
    public function index(): void
    {
        $stmt = Database::pdo()->query("
            SELECT l.*, u.username 
            FROM audit_logs l
            JOIN users u ON l.user_id = u.id
            ORDER BY l.id DESC LIMIT 100
        ");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        View::render('management/audit', ['logs' => $logs], 'management');
    }
}