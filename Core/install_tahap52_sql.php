<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    $check = $pdo->query("SHOW COLUMNS FROM employees LIKE 'birth_date'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN birth_date DATE NULL AFTER full_name");
    }
    echo "✅ Schema Tahap 52 siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}