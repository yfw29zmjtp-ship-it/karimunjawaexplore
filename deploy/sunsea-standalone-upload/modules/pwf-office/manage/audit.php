<?php

/**
 * PWF Management - Audit Log (Coming Soon)
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../layout.php';
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Audit Log - PWF Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f5f3f0;
            color: #1c1511;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #B8860B;
            text-decoration: none;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .1);
        }

        .card i {
            font-size: 64px;
            color: #B8860B;
            margin-bottom: 16px;
        }

        .card h1 {
            font-size: 24px;
            margin-bottom: 12px;
        }

        .card p {
            color: #666;
            margin-bottom: 24px;
        }

        .btn {
            padding: 10px 20px;
            background: #B8860B;
            color: white;
            border: none;
            border-radius: 6px;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="index.php" class="back-link">← Kembali</a>
        <div class="card">
            <i class="bi bi-clock-history"></i>
            <h1>Audit Log</h1>
            <p>Fitur ini sedang dalam pengembangan. Silakan kembali ke halaman utama.</p>
            <a href="index.php" class="btn">Kembali ke PWF Management</a>
        </div>
    </div>
</body>

</html>