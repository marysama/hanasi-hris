<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS master_documents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_type VARCHAR(50) NOT NULL UNIQUE,
        file_path VARCHAR(255) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Tabel master_documents siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}