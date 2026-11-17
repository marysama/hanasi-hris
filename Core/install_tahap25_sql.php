<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Hapus tabel employee_salaries lama & buat baru dengan kolom lengkap
    $pdo->exec("DROP TABLE IF EXISTS employee_salaries");
    
    $pdo->exec("CREATE TABLE employee_salaries (
        employee_id BIGINT UNSIGNED PRIMARY KEY,
        basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
        allowance_position DECIMAL(15,2) NOT NULL DEFAULT 0,
        overtime_rate DECIMAL(15,2) NOT NULL DEFAULT 0,
        deduction_bpjs_tk DECIMAL(15,2) NOT NULL DEFAULT 0,
        deduction_bpjs_kes DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Hapus tabel payrolls lama & buat baru (Snapshot lengkap)
    $pdo->exec("DROP TABLE IF EXISTS payrolls");
    
    $pdo->exec("CREATE TABLE payrolls (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        period_month VARCHAR(7) NOT NULL,
        basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
        allowance_position DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_overtime DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_backup DECIMAL(15,2) NOT NULL DEFAULT 0,
        deduction_absence DECIMAL(15,2) NOT NULL DEFAULT 0,
        deduction_bpjs_tk DECIMAL(15,2) NOT NULL DEFAULT 0,
        deduction_bpjs_kes DECIMAL(15,2) NOT NULL DEFAULT 0,
        net_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
        status ENUM('draft', 'final') DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_payroll (employee_id, period_month),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "✅ Schema Tabel Payroll Tahap 25 berhasil dibuat.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}