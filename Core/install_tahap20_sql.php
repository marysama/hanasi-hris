<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tambah kolom 'level' di tabel roles (Tingkat hierarki jabatan)
    $checkCol = $pdo->query("SHOW COLUMNS FROM roles LIKE 'level'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN level INT DEFAULT 1 AFTER name");
        // Set level default: Employee=1, Supervisor=2, Manager=3, HR=4, Admin=5
        $pdo->exec("UPDATE roles SET level = 1 WHERE code = 'employee'");
        $pdo->exec("UPDATE roles SET level = 2 WHERE code IN ('supervisor', 'hr_officer')");
        $pdo->exec("UPDATE roles SET level = 3 WHERE code IN ('finance', 'hr_admin')");
        $pdo->exec("UPDATE roles SET level = 4 WHERE code IN ('gm')");
        $pdo->exec("UPDATE roles SET level = 5 WHERE code IN ('sysadmin')");
        echo "✅ Kolom 'level' ditambahkan ke tabel roles.<br>";
    }

    // 2. Buat tabel Work Areas (Area Kerja)
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_areas (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        radius_m INT NOT NULL DEFAULT 100,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Tabel work_areas berhasil dibuat.<br>";

    // 3. Tambah kolom area_id & is_mobile di tabel employees
    $checkEmp = $pdo->query("SHOW COLUMNS FROM employees LIKE 'area_id'")->fetch();
    if (!$checkEmp) {
        $pdo->exec("ALTER TABLE employees 
            ADD COLUMN area_id BIGINT UNSIGNED NULL AFTER full_name,
            ADD COLUMN is_mobile TINYINT(1) DEFAULT 0 AFTER area_id,
            ADD COLUMN is_shift_required TINYINT(1) DEFAULT 1 AFTER is_mobile
        ");
        echo "✅ Kolom area & tipe karyawan ditambahkan ke tabel employees.<br>";
    }

    echo "<br><b>Schema Tahap 20 Selesai.</b>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}