<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Set default minimal level untuk akses modul
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
        ('min_level_holiday', '3'),   -- Minimal Supervisor/AM untuk atur hari libur
        ('min_level_payroll', '8'),   -- Minimal Admin/HR untuk atur gaji
        ('min_level_employees', '8')  -- Minimal Admin/HR untuk kelola karyawan
    ");
    echo "✅ Pengaturan default RBAC berhasil ditambahkan.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}