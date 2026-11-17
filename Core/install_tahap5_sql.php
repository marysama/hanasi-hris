<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Tabel Tugas
    $sql1 = "
    CREATE TABLE IF NOT EXISTS tasks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(150) NOT NULL,
        site VARCHAR(100) NOT NULL,
        status ENUM('belum', 'proses', 'selesai') DEFAULT 'belum',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql1);
    echo "✅ Tabel tasks berhasil dibuat.<br>";

    // Tabel Cuti (Update untuk menyimpan siapa yang ACC)
    $pdo->exec("ALTER TABLE leave_requests ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER status");
    echo "✅ Kolom approved_by ditambahkan ke leave_requests.<br>";

} catch (\Exception $e) {
    // Abaikan jika kolom sudah ada (error code 1060)
    if ($e->getCode() != '42S21' && strpos($e->getMessage(), 'Duplicate column') === false) {
        echo "❌ Error SQL: " . $e->getMessage() . "<br>";
    } else {
        echo "✅ Schema Tahap 5 sudah siap.<br>";
    }
}