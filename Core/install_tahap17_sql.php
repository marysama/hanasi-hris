<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Tambah Roles sesuai Blueprint
    $roles = [
        ['code' => 'sysadmin', 'name' => 'System Administrator'],
        ['code' => 'hr_admin', 'name' => 'HR Admin'],
        ['code' => 'hr_officer', 'name' => 'HR Officer'],
        ['code' => 'supervisor', 'name' => 'Supervisor / Team Leader'],
        ['code' => 'finance', 'name' => 'Finance / Payroll'],
        ['code' => 'gm', 'name' => 'GM / Director'],
        ['code' => 'employee', 'name' => 'Employee (Cleaning Staff)'],
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO roles (code, name) VALUES (:code, :name)");
    foreach ($roles as $r) {
        $stmt->execute(['code' => $r['code'], 'name' => $r['name']]);
    }

    // Pastikan Permission manajemen & karyawan tersedia
    $perms = [
        ['code' => 'management_access', 'name' => 'Akses Portal Manajemen'],
        ['code' => 'employee_access', 'name' => 'Akses Portal Karyawan'],
        ['code' => 'payroll_access', 'name' => 'Akses Modul Payroll']
    ];
    $stmtPerm = $pdo->prepare("INSERT IGNORE INTO permissions (code, name) VALUES (:code, :name)");
    foreach ($perms as $p) {
        $stmtPerm->execute(['code' => $p['code'], 'name' => $p['name']]);
    }

    echo "✅ Roles & Permissions standar HRIS berhasil di-seed.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}