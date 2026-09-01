<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "<!-- step1: PHP running -->\n";

// Simulate warehouse.php step by step
try {
    require_once __DIR__ . '/_bootstrap.php';
    echo "<!-- step2: _bootstrap.php OK -->\n";
} catch (\Throwable $e) {
    echo "<pre>BOOTSTRAP ERROR: " . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    exit;
}

echo "<!-- step3: after bootstrap -->\n";

try {
    require_once __DIR__ . '/db-helper.php';
    echo "<!-- step4: db-helper OK -->\n";
    $pdo = getPwfOfficePdo();
    echo "<!-- step5: PDO OK -->\n";
} catch (\Throwable $e) {
    echo "<pre>DB ERROR: " . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

echo "<!-- step6: calling pwfOfficeHeader -->\n";

try {
    require_once __DIR__ . '/layout.php';
    echo "<!-- step7: layout.php loaded -->\n";
    pwfOfficeHeader('WH Test', 'warehouse');
    echo "<!-- step8: pwfOfficeHeader done -->\n";
} catch (\Throwable $e) {
    echo "<pre>LAYOUT ERROR: " . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    exit;
}

echo "<div style='padding:20px;background:green;color:white'>ALL STEPS OK - warehouse.php should work</div>";

pwfOfficeFooter();
