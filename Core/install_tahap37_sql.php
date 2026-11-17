<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    $checkCol1 = $pdo->query("SHOW COLUMNS FROM employees LIKE 'phone'")->fetch();
    if (!$checkCol1) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN phone VARCHAR(20) NULL AFTER no_bpjs_kes");
    }
    $checkCol2 = $pdo->query("SHOW COLUMNS FROM employees LIKE 'address'")->fetch();
    if (!$checkCol2) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN address TEXT NULL AFTER phone");
    }
    echo "✅ Kolom phone & address siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}