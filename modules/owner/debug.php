?php
ession_start();
>
!DOCTYPE html>
html>
head>
   <title>Owner Dashboard Debug</title>
   <style>
       body { font-family: Arial; padding: 20px; }
       .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; }
       img { max-width: 200px; border: 2px solid #4338ca; }
   </style>
/head>
body>
   <h1>Owner Dashboard Debug</h1>
   
   <div class="section">
       <h2>Logo Test</h2>
       <?php
       $uploadsDir = dirname(dirname(__DIR__)) . '/uploads/logos/';
       echo "<p><strong>Full Path:</strong> " . $uploadsDir . "</p>";
       echo "<p><strong>Directory exists:</strong> " . (is_dir($uploadsDir) ? 'Yes ✓' : 'No ✗') . "</p>";
       
       $logos = glob($uploadsDir . 'hotel_logo_*.png');
       echo "<p><strong>Found logos:</strong> " . count($logos) . "</p>";
       
       if (!empty($logos)) {
           $logoFile = basename(end($logos));
           echo "<p><strong>Selected logo:</strong> $logoFile</p>";
           echo '<img src="../../uploads/logos/' . $logoFile . '" alt="Logo">';
       }
       ?>
   </div>
   
   <div class="section">
       <h2>API Test - Chart Data</h2>
       <button onclick="testChartAPI()">Test Chart API</button>
       <div id="chartResult"></div>
   </div>
   
   <div class="section">
       <h2>API Test - Stats</h2>
       <button onclick="testStatsAPI()">Test Stats API</button>
       <div id="statsResult"></div>
   </div>
   
   <script>
       async function testChartAPI() {
           const result = document.getElementById('chartResult');
           result.innerHTML = 'Loading...';
           
           try {
               const response = await fetch('../../api/owner-chart-data.php?period=7days');
               const data = await response.json();
               result.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
           } catch (error) {
               result.innerHTML = '<p style="color: red;">Error: ' + error.message + '</p>';
           }
       }
       
       async function testStatsAPI() {
           const result = document.getElementById('statsResult');
           result.innerHTML = 'Loading...';
           
           try {
               const response = await fetch('../../api/owner-stats.php');
               const data = await response.json();
               result.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
           } catch (error) {
               result.innerHTML = '<p style="color: red;">Error: ' + error.message + '</p>';
           }
       }
   </script>
/body>
/html>
