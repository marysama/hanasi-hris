<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    $check = $pdo->query("SHOW COLUMNS FROM employees LIKE 'payroll_cycle'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN payroll_cycle ENUM('26-25', '1-31') DEFAULT '26-25' AFTER is_mobile");
        echo "✅ Kolom 'payroll_cycle' ditambahkan ke tabel employees.<br>";
    }
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}