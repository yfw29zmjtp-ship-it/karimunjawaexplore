<?php

/**
 * Database investigation script
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';

$masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Set charset
$masterPdo->exec("SET NAMES utf8mb4");
$masterPdo->exec("SET CHARACTER SET utf8mb4");

echo "<pre style='background:#f5f5f5;padding:20px;border-radius:8px;font-family:monospace;font-size:11px;'>";

echo "=== DATABASE & TABLE INFO ===\n";
echo "Current Database: " . DB_NAME . "\n";
echo "Host: " . DB_HOST . "\n\n";

// Get full CREATE TABLE statement
echo "=== SHOW CREATE TABLE users ===\n";
$result = $masterPdo->query("SHOW CREATE TABLE users");
if ($result) {
    $row = $result->fetch();
    echo $row['Create Table'] ?? 'Not found';
} else {
    echo "Error: " . print_r($masterPdo->errorInfo(), true);
}

echo "\n\n=== SHOW TRIGGERS on users ===\n";
$triggers = $masterPdo->query("SHOW TRIGGERS WHERE `Table` = 'users'")->fetchAll();
if (count($triggers) > 0) {
    foreach ($triggers as $t) {
        echo "Trigger: {$t['Trigger']}\n";
        echo "  Event: {$t['Event']}\n";
        echo "  Timing: {$t['Timing']}\n";
    }
} else {
    echo "No triggers\n";
}

echo "\n\n=== SHOW KEYS from users ===\n";
$keys = $masterPdo->query("SHOW KEYS FROM users")->fetchAll();
foreach ($keys as $k) {
    echo "{$k['Key_name']}: {$k['Column_name']} (Unique: " . ($k['Non_unique'] ? 'No' : 'Yes') . ")\n";
}

echo "\n\n=== test Connection & Charset ===\n";
$charset = $masterPdo->query("SELECT @@character_set_client, @@character_set_connection, @@character_set_database")->fetch();
echo "Charset Client: {$charset['@@character_set_client']}\n";
echo "Charset Connection: {$charset['@@character_set_connection']}\n";
echo "Charset Database: {$charset['@@character_set_database']}\n";

echo "\n\n=== Test INSERT Minimal ===\n";
$testUsername = 'debug_' . time();
$testEmail = 'debug_' . time() . '@test.local';
$testPass = password_hash('Test@123', PASSWORD_BCRYPT);

$sql = "INSERT INTO users (username, email, password, full_name) VALUES (?, ?, ?, ?)";
$stmt = $masterPdo->prepare($sql);

echo "SQL: $sql\n";
echo "Params:\n";
echo "  username: '$testUsername'\n";
echo "  email: '$testEmail'\n";
echo "  password: (hashed)\n";
echo "  full_name: 'Test User'\n\n";

if ($stmt->execute([$testUsername, $testEmail, $testPass, 'Test User'])) {
    $userId = $masterPdo->lastInsertId();
    echo "✓ INSERT Successful!\n";
    echo "  User ID: $userId\n";

    // Verify
    $check = $masterPdo->query("SELECT * FROM users WHERE id = $userId")->fetch();
    if ($check) {
        echo "\n✓ User verified in database:\n";
        foreach ($check as $k => $v) {
            echo "  $k: $v\n";
        }
    }

    // Cleanup
    $masterPdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    echo "\n✓ Test data cleaned up\n";
} else {
    echo "✗ INSERT Failed!\n";
    $err = $stmt->errorInfo();
    echo "  SQLSTATE: {$err[0]}\n";
    echo "  Error Code: {$err[1]}\n";
    echo "  Error Message: {$err[2]}\n";
}

echo "\n</pre>";
