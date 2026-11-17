<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    // Ubah panjang kolom weekly_off_day jadi 100 (buat nampung banyak hari misal "Senin,Selasa,Rabu")
    $pdo->exec("ALTER TABLE employees MODIFY COLUMN weekly_off_day VARCHAR(100) NULL");
    echo "✅ Kolom 'weekly_off_day' diperlebar untuk multi-hari.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}