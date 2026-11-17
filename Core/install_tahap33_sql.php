<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    $checkCol = $pdo->query("SHOW COLUMNS FROM employees LIKE 'weekly_off_day'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN weekly_off_day VARCHAR(50) NULL AFTER is_night_shift_eligible");
        echo "✅ Kolom 'weekly_off_day' berhasil ditambahkan ke tabel employees.<br>";
    } else {
        echo "✅ Kolom 'weekly_off_day' sudah ada.<br>";
    }
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}