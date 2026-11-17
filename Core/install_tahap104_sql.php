<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    $check = $pdo->query("SHOW COLUMNS FROM payrolls LIKE 'deduction_alfa'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE payrolls 
            ADD COLUMN deduction_alfa DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER allowance_position,
            ADD COLUMN deduction_izin DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER deduction_alfa,
            ADD COLUMN deduction_sakit DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER deduction_izin
        ");
        echo "✅ Kolom rincian potongan ditambahkan.<br>";
    }
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}