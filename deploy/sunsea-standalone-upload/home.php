<?php
/**
 * NARAYANA MULTI-BUSINESS SYSTEM
 * Halaman Utama - 3 Link Penting
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
>
!DOCTYPE html>
html lang="id">
head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Narayana Multi-Business System</title>
   <style>
       * {
           margin: 0;
           padding: 0;
           box-sizing: border-box;
       }
       
       body {
           font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
           background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
           min-height: 100vh;
           display: flex;
           align-items: center;
           justify-content: center;
           padding: 20px;
       }
       
       .container {
           max-width: 1000px;
           width: 100%;
       }
       
       .header {
           text-align: center;
           margin-bottom: 50px;
           color: white;
       }
       
       .header h1 {
           font-size: 42px;
           margin-bottom: 10px;
           text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
       }
       
       .header p {
           font-size: 18px;
           opacity: 0.9;
       }
       
       .cards-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
           gap: 30px;
       }
       
       .card {
           background: white;
           border-radius: 15px;
           padding: 40px 30px;
           text-align: center;
           box-shadow: 0 10px 30px rgba(0,0,0,0.2);
           transition: all 0.3s;
           cursor: pointer;
           text-decoration: none;
           display: block;
       }
       
       .card:hover {
           transform: translateY(-10px);
           box-shadow: 0 15px 40px rgba(0,0,0,0.3);
       }
       
       .card .icon {
           font-size: 64px;
           margin-bottom: 20px;
       }
       
       .card h2 {
           font-size: 24px;
           margin-bottom: 15px;
           color: #333;
       }
       
       .card p {
           color: #666;
           line-height: 1.6;
           margin-bottom: 20px;
       }
       
       .card .btn {
           display: inline-block;
           padding: 12px 30px;
           border-radius: 25px;
           font-weight: bold;
           text-decoration: none;
           transition: all 0.3s;
       }
       
       .card.system .btn {
           background: #3498db;
           color: white;
       }
       
       .card.system:hover .btn {
           background: #2980b9;
       }
       
       .card.owner .btn {
           background: #667eea;
           color: white;
       }
       
       .card.owner:hover .btn {
           background: #5568d3;
       }
       
       .card.developer .btn {
           background: #e74c3c;
           color: white;
       }
       
       .card.developer:hover .btn {
           background: #c0392b;
       }
       
       .footer {
           text-align: center;
           margin-top: 40px;
           color: white;
           opacity: 0.8;
       }
       
       .url-box {
           background: rgba(255,255,255,0.1);
           padding: 15px;
           border-radius: 8px;
           margin-top: 40px;
           text-align: center;
       }
       
       .url-box code {
           background: rgba(0,0,0,0.3);
           padding: 8px 15px;
           border-radius: 5px;
           color: white;
           font-size: 14px;
           display: inline-block;
           margin: 5px;
       }
   </style>
</head>
<body>
   <div class="container">
       <div class="header">
           <h1>🏢 Narayana Multi-Business</h1>
           <p>Pilih sistem yang ingin Anda akses</p>
       </div>
       
       <div class="cards-grid">
           <!-- 1. SYSTEM (Staff/Manager) -->
           <a href="<?php echo BASE_URL; ?>/login.php" class="card system">
               <div class="icon">💼</div>
               <h2>System Login</h2>
               <p>Untuk Staff, Manager, dan Accountant. Akses ke modul cashbook, procurement, sales, dan reports.</p>
               <span class="btn">Masuk System →</span>
           </a>
           
           <!-- 2. OWNER -->
           <a href="<?php echo BASE_URL; ?>/owner-login.php" class="card owner">
               <div class="icon">👔</div>
               <h2>Owner Dashboard</h2>
               <p>Untuk pemilik bisnis. Monitor semua bisnis, lihat laporan, dan analisa performa.</p>
               <span class="btn">Login Owner →</span>
           </a>
           
           <!-- 3. DEVELOPER -->
           <a href="<?php echo BASE_URL; ?>/tools/developer-panel.php" class="card developer">
               <div class="icon">⚙️</div>
               <h2>Developer Panel</h2>
               <p>Setup user owner, atur akses bisnis, kelola system, dan tools developer.</p>
               <span class="btn">Developer Setup →</span>
           </a>
       </div>
       
       <div class="url-box">
           <p style="color: white; margin-bottom: 10px;">📋 <strong>Simpan 3 Link Ini:</strong></p>
           <code>http://localhost:8080/narayana/login.php</code>
           <code>http://localhost:8080/narayana/owner-login.php</code>
           <code>http://localhost:8080/narayana/tools/developer-panel.php</code>
       </div>
       
       <div class="footer">
           <p>© 2026 Narayana Multi-Business System</p>
       </div>
   </div>
</body>
</html>
