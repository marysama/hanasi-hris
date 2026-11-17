<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    $check1 = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_read'")->fetch();
    if (!$check1) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER message");
    }
    echo "✅ Schema Tahap 55 siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}