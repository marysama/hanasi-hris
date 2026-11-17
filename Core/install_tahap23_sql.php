<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tambah kolom di employees (Atasan Langsung, KPJ, BPJS, Status Kerja)
    $checkEmp = $pdo->query("SHOW COLUMNS FROM employees LIKE 'supervisor_id'")->fetch();
    if (!$checkEmp) {
        $pdo->exec("ALTER TABLE employees 
            ADD COLUMN supervisor_id BIGINT UNSIGNED NULL AFTER area_id,
            ADD COLUMN no_kpj VARCHAR(50) NULL AFTER is_shift_required,
            ADD COLUMN no_bpjs_kes VARCHAR(50) NULL AFTER no_kpj,
            ADD COLUMN employment_status ENUM('aktif', 'nonaktif', 'resign') DEFAULT 'aktif' AFTER no_bpjs_kes
        ");
    }

    // 2. Hapus Shift Default (Karena mau custom) & tambah kolom toleransi
    $pdo->exec("ALTER TABLE shifts 
        ADD COLUMN is_night_shift TINYINT(1) DEFAULT 0 AFTER end_time,
        ADD COLUMN tolerance_minutes INT DEFAULT 15 AFTER is_night_shift
    ");

    // 3. Isi Data Area Dummy Bandung (Ganti Monas)
    $pdo->exec("DELETE FROM work_areas WHERE name = 'Monas'");
    $pdo->exec("INSERT IGNORE INTO work_areas (name, latitude, longitude, radius_m) VALUES 
        ('Gedung A Bandung', -6.914744, 107.609810, 100),
        ('Gedung B Bandung', -6.917464, 107.619123, 100)
    ");

    echo "✅ Schema Tahap 23 berhasil disiapkan.<br>";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "✅ Schema Tahap 23 sudah siap.<br>";
    } else {
        echo "❌ Error SQL: " . $e->getMessage() . "<br>";
    }
}