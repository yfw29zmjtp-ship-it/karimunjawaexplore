<?php
// git-fix-working-tree.php — Reset modified files to match HEAD commit from GitHub
// DELETE THIS FILE AFTER USE

$token = isset($_GET['token']) ? $_GET['token'] : '';
if ($token !== 'adf-fix-2025') {
    http_response_code(403);
    die('Forbidden');
}

$commit = '6efec2cf01a7a32e9658d4b4c2fe065210300dd5';
$repo = 'ratn050-oss/adf_system';
$basePath = __DIR__;

$files = [
    'modules/payroll/process.php',
    'modules/payroll/attendance-clock.php',
];

$results = [];

foreach ($files as $file) {
    $url = "https://raw.githubusercontent.com/{$repo}/{$commit}/{$file}";

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'PHP/ADF-Fix'
        ]
    ]);

    $content = @file_get_contents($url, false, $ctx);

    if ($content === false) {
        $results[] = "FAIL: {$file} — could not download from GitHub";
        continue;
    }

    $localPath = $basePath . '/' . $file;
    $written = @file_put_contents($localPath, $content);

    if ($written === false) {
        $results[] = "FAIL: {$file} — could not write to disk";
    } else {
        $results[] = "OK: {$file} — {$written} bytes written";
    }
}

header('Content-Type: text/plain');
echo "Git Working Tree Fix\n";
echo "====================\n";
echo "Commit: {$commit}\n\n";
echo implode("\n", $results) . "\n";
echo "\nDone. Now try 'Update from Remote' in cPanel Git.\n";
echo "REMEMBER: Delete this file after use!\n";
