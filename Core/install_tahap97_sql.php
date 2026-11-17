<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    $check = $pdo->query("SHOW COLUMNS FROM work_areas LIKE 'sick_deduction_percent'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE work_areas ADD COLUMN sick_deduction_percent INT DEFAULT 50 AFTER radius_m");
        echo "✅ Kolom 'sick_deduction_percent' ditambahkan ke tabel work_areas.<br>";
    }
    
    // Update semua area yang ada agar defaultnya 50%
    $pdo->exec("UPDATE work_areas SET sick_deduction_percent = 50 WHERE sick_deduction_percent IS NULL");
    
    echo "✅ Schema Tahap 97 siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}