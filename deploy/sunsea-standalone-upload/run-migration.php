<?php

/**
 * Execute SQL migration for monthly bills - Direct local connection
 */

try {
    // Direct connection to local database
    $pdo = new PDO(
        'mysql:host=localhost;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // Use the correct business database
    $pdo->exec("USE adf_narayana_hotel");

    // Read SQL file
    $sqlFile = __DIR__ . '/sql/setup-monthly-bills.sql';
    $sql = file_get_contents($sqlFile);

    // Remove comments and split by semicolon
    $lines = explode("\n", $sql);
    $statements = [];
    $currentStatement = "";

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip empty lines and comments
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }

        $currentStatement .= " " . $line;

        // If line ends with semicolon, it's a complete statement
        if (substr($line, -1) === ';') {
            $statements[] = trim($currentStatement);
            $currentStatement = "";
        }
    }

    // Execute each statement
    $count = 0;
    foreach ($statements as $statement) {
        if (!empty(trim($statement))) {
            try {
                $pdo->exec($statement);
                $count++;
                echo "✓ Executed statement $count\n";
            } catch (Exception $e) {
                echo "⚠ Statement error: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n✅ Database migration completed!\n";
    echo "✅ Executed $count SQL statements\n";

    // Verify tables created
    $tables = $pdo->query("SHOW TABLES LIKE 'monthly%'")->fetchAll(PDO::FETCH_ASSOC);
    echo "\n📊 Tables created:\n";
    foreach ($tables as $table) {
        $tableName = array_values($table)[0];
        echo "  ✓ $tableName\n";
    }

    // Show table structure
    echo "\n📋 Table Structure:\n";
    $columns = $pdo->query("DESCRIBE monthly_bills")->fetchAll(PDO::FETCH_ASSOC);
    echo "  monthly_bills: " . count($columns) . " columns\n";

    $columns = $pdo->query("DESCRIBE bill_payments")->fetchAll(PDO::FETCH_ASSOC);
    echo "  bill_payments: " . count($columns) . " columns\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
