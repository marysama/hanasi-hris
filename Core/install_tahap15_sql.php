<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tabel Gaji Karyawan (Master)
    $sql1 = "
    CREATE TABLE IF NOT EXISTS employee_salaries (
        employee_id BIGINT UNSIGNED PRIMARY KEY,
        basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql1);

    // 2. Tabel Riwayat Payroll (Dihasilkan per bulan)
    $sql2 = "
    CREATE TABLE IF NOT EXISTS payrolls (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        period_month VARCHAR(7) NOT NULL, -- Format: YYYY-MM
        basic_salary DECIMAL(15,2) NOT NULL,
        total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
        net_salary DECIMAL(15,2) NOT NULL,
        status ENUM('draft', 'final') DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_payroll (employee_id, period_month),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql2);

    echo "✅ Tabel employee_salaries & payrolls berhasil dibuat.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}