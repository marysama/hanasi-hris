<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tambah kolom is_read di notifications
    $check1 = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_read'")->fetch();
    if (!$check1) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER message");
    }

    // 2. Tambah kolom status_review & rejection_reason di employee_documents
    $check2 = $pdo->query("SHOW COLUMNS FROM employee_documents LIKE 'status_review'")->fetch();
    if (!$check2) {
        $pdo->exec("ALTER TABLE employee_documents ADD COLUMN status_review ENUM('menunggu', 'disetujui', 'ditolak') DEFAULT 'menunggu' AFTER file_path");
        $pdo->exec("ALTER TABLE employee_documents ADD COLUMN rejection_reason TEXT NULL AFTER status_review");
    }

    echo "✅ Schema Tahap 54 siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}