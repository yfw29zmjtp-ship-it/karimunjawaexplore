<?php

/**
 * Migration Runner: Add ota_source_detail column
 * This script adds the missing ota_source_detail column to the bookings table
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    echo "Starting migration: Adding ota_source_detail column...\n";

    // Check if column already exists
    $checkStmt = $conn->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'ota_source_detail'
    ");
    $checkStmt->execute([getenv('DB_NAME') ?: 'adf2574_narayana_hotel']);

    if ($checkStmt->rowCount() > 0) {
        echo "✅ Column ota_source_detail already exists. No changes needed.\n";
        exit(0);
    }

    // Column doesn't exist, add it
    $alterSql = "
        ALTER TABLE bookings 
        ADD COLUMN ota_source_detail VARCHAR(50) DEFAULT NULL 
        COMMENT 'OTA platform name (agoda, booking, traveloka, airbnb, expedia, pegipegi, etc)' 
        AFTER booking_source
    ";

    $conn->exec($alterSql);

    echo "✅ Successfully added ota_source_detail column to bookings table\n";

    // Verify
    $verifyStmt = $conn->prepare("
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'ota_source_detail'
    ");
    $verifyStmt->execute([getenv('DB_NAME') ?: 'adf2574_narayana_hotel']);
    $result = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo "✅ Verification successful:\n";
        echo "   Column: " . $result['COLUMN_NAME'] . "\n";
        echo "   Type: " . $result['COLUMN_TYPE'] . "\n";
        echo "   Nullable: " . $result['IS_NULLABLE'] . "\n";
    }
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
