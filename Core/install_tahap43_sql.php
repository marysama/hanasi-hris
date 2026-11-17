<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Foto Profil di tabel employees
    $check1 = $pdo->query("SHOW COLUMNS FROM employees LIKE 'photo_path'")->fetch();
    if (!$check1) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN photo_path VARCHAR(255) NULL AFTER address");
    }

    // 2. Pastikan tabel employee_documents ada
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "✅ Schema Tahap 43 siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}