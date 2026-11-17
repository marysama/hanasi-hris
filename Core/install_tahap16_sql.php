<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Tambah kolom komponen di employee_salaries
    $pdo->exec("ALTER TABLE employee_salaries 
        ADD COLUMN allowance_position DECIMAL(15,2) DEFAULT 0 AFTER basic_salary,
        ADD COLUMN allowance_transport DECIMAL(15,2) DEFAULT 0 AFTER allowance_position,
        ADD COLUMN allowance_meal DECIMAL(15,2) DEFAULT 0 AFTER allowance_transport,
        ADD COLUMN deduction_bpjs DECIMAL(15,2) DEFAULT 0 AFTER allowance_meal,
        ADD COLUMN deduction_tax DECIMAL(15,2) DEFAULT 0 AFTER deduction_bpjs
    ");

    // Tambah kolom komponen di payrolls (untuk snapshot histori)
    $pdo->exec("ALTER TABLE payrolls 
        ADD COLUMN allowance_position DECIMAL(15,2) DEFAULT 0 AFTER basic_salary,
        ADD COLUMN allowance_transport DECIMAL(15,2) DEFAULT 0 AFTER allowance_position,
        ADD COLUMN allowance_meal DECIMAL(15,2) DEFAULT 0 AFTER allowance_transport,
        ADD COLUMN deduction_bpjs DECIMAL(15,2) DEFAULT 0 AFTER total_deductions,
        ADD COLUMN deduction_tax DECIMAL(15,2) DEFAULT 0 AFTER deduction_bpjs
    ");
    
    echo "✅ Kolom rincian gaji berhasil ditambahkan.<br>";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "✅ Kolom rincian gaji sudah siap.<br>";
    } else {
        echo "❌ Error SQL: " . $e->getMessage() . "<br>";
    }
}