<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Tambah kolom Tgl Lahir & Tgl Masuk di employees
    $check1 = $pdo->query("SHOW COLUMNS FROM employees LIKE 'birth_date'")->fetch();
    if (!$check1) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN birth_date DATE NULL AFTER full_name");
        $pdo->exec("ALTER TABLE employees ADD COLUMN join_date DATE NULL AFTER birth_date");
    }

    // Tabel Dokumen Karyawan (PKWT, KTP, dll)
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "✅ Schema Tahap 42 siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}