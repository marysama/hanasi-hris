<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Cek apakah kolom remember_token ada di tabel users
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'remember_token'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE users ADD COLUMN remember_token VARCHAR(255) NULL AFTER password_hash");
    }

    echo "✅ Schema Tahap 41 siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}