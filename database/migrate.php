<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration Runner</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-info {
            background: #e3f2fd;
            border-color: #2196F3;
            color: #1565c0;
        }

        .alert-success {
            background: #e8f5e9;
            border-color: #4caf50;
            color: #2e7d32;
        }

        .alert-error {
            background: #ffebee;
            border-color: #f44336;
            color: #c62828;
        }

        .button {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            margin-right: 10px;
        }

        .button:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .code-block {
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 15px 0;
            line-height: 1.5;
        }

        .steps {
            margin-top: 30px;
        }

        .step {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .step:last-child {
            border-bottom: none;
        }

        .step-number {
            display: inline-block;
            background: #667eea;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            text-align: center;
            line-height: 32px;
            font-weight: bold;
            margin-right: 12px;
        }

        .step-content {
            display: inline-block;
            vertical-align: top;
            width: calc(100% - 50px);
        }

        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .status-pending {
            background: #ffc107;
        }

        .status-success {
            background: #4caf50;
        }

        .status-error {
            background: #f44336;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🔧 Database Migration Runner</h1>
        <p class="subtitle">Fix: Add ota_source_detail column to bookings table</p>

        <div class="alert alert-info">
            <strong>ℹ️ Info:</strong> This tool will add the missing <code>ota_source_detail</code> column to your bookings table. This column is required for the front desk calendar to work properly.
        </div>

        <div id="status-area"></div>

        <div>
            <button class="button" onclick="runMigration()" id="runBtn">▶️ Run Migration</button>
            <button class="button" onclick="checkStatus()" id="checkBtn">✓ Check Status</button>
        </div>

        <div class="steps">
            <h2>Migration Details</h2>

            <div class="step">
                <span class="step-number">1</span>
                <div class="step-content">
                    <strong>What's being added:</strong>
                    <p>Column name: <code>ota_source_detail</code></p>
                    <p>Type: <code>VARCHAR(50)</code></p>
                    <p>Purpose: Stores the specific OTA platform name (agoda, booking, traveloka, airbnb, expedia, pegipegi, etc.)</p>
                </div>
            </div>

            <div class="step">
                <span class="step-number">2</span>
                <div class="step-content">
                    <strong>SQL Command:</strong>
                    <div class="code-block">ALTER TABLE bookings
                        ADD COLUMN IF NOT EXISTS ota_source_detail VARCHAR(50) DEFAULT NULL
                        COMMENT 'OTA platform name (agoda, booking, traveloka, airbnb, expedia, pegipegi, etc)'
                        AFTER booking_source;</div>
                </div>
            </div>

            <div class="step">
                <span class="step-number">3</span>
                <div class="step-content">
                    <strong>Why it's needed:</strong>
                    <p>The front desk calendar and booking management system reference this column, but it was missing from the database schema. This causes SQL errors when trying to load booking details.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateStatus(message, type = 'info') {
            const statusArea = document.getElementById('status-area');
            const alertClass = `alert alert-${type}`;
            statusArea.innerHTML = `<div class="${alertClass}">${message}</div>`;
        }

        function disableButtons(disabled) {
            document.getElementById('runBtn').disabled = disabled;
            document.getElementById('checkBtn').disabled = disabled;
        }

        async function runMigration() {
            updateStatus('<span class="status-indicator status-pending"></span> Running migration...', 'info');
            disableButtons(true);

            try {
                const response = await fetch('migrate-api.php?action=run', {
                    method: 'GET'
                });

                const data = await response.json();

                if (data.success) {
                    updateStatus('<span class="status-indicator status-success"></span> ✅ ' + data.message, 'success');
                } else {
                    updateStatus('<span class="status-indicator status-error"></span> ❌ ' + data.message, 'error');
                }
            } catch (error) {
                updateStatus('<span class="status-indicator status-error"></span> ❌ ' + error.message, 'error');
            } finally {
                disableButtons(false);
            }
        }

        async function checkStatus() {
            updateStatus('<span class="status-indicator status-pending"></span> Checking column status...', 'info');
            disableButtons(true);

            try {
                const response = await fetch('migrate-api.php?action=check', {
                    method: 'GET'
                });

                const data = await response.json();

                if (data.exists) {
                    updateStatus('<span class="status-indicator status-success"></span> ✅ Column already exists! No migration needed.', 'success');
                } else {
                    updateStatus('<span class="status-indicator status-error"></span> ⚠️ Column not found. Click "Run Migration" to add it.', 'error');
                }
            } catch (error) {
                updateStatus('<span class="status-indicator status-error"></span> ❌ ' + error.message, 'error');
            } finally {
                disableButtons(false);
            }
        }

        // Check status on page load
        window.addEventListener('load', checkStatus);
    </script>
</body>

</html>