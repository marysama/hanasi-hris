<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Tabel Master Shift
    $sql1 = "
    CREATE TABLE IF NOT EXISTS shifts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql1);
    
    // Tabel Jadwal Karyawan (Siapa, Tanggal Berapa, Shift Apa)
    $sql2 = "
    CREATE TABLE IF NOT EXISTS employee_shifts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        shift_id BIGINT UNSIGNED NOT NULL,
        work_date DATE NOT NULL,
        UNIQUE KEY unique_shift_date (employee_id, work_date),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql2);

    // Insert Default Shift jika belum ada
    $pdo->exec("INSERT IGNORE INTO shifts (id, name, start_time, end_time) VALUES (1, 'Shift Pagi', '08:00:00', '17:00:00'), (2, 'Shift Malam', '20:00:00', '05:00:00')");

    echo "✅ Tabel shifts & employee_shifts berhasil dibuat.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}