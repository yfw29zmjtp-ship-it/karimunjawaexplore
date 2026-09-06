<?php
// Shared standalone mobile header for Owner "app" views.
// Expects $pageTitle set before include; optional $backUrl (default owner-dashboard.php).
$backUrl = $backUrl ?? 'owner-dashboard.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($pageTitle); ?> - Owner</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<script src="https://unpkg.com/feather-icons"></script>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    :root {
        --ocean:#0369A1; --success:#059669; --danger:#DC2626; --warning:#D97706; --muted:#64748B;
        --text:#1E293B; --sky:#F0F9FF; --border:#E2E8F0;
    }
    body {
        font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
        background:#F8FAFC; color:var(--text); font-size:14px; padding-bottom:24px;
    }
    .ob-topbar {
        display:flex; align-items:center; justify-content:space-between; gap:8px;
        background:linear-gradient(135deg,#0369A1 0%,#0EA5E9 100%); color:#fff;
        padding:14px 12px; position:sticky; top:0; z-index:10;
    }
    .ob-back {
        width:30px; height:30px; border-radius:50%; background:rgba(255,255,255,.2);
        display:flex; align-items:center; justify-content:center; color:#fff; text-decoration:none; flex-shrink:0;
    }
    .ob-back svg { width:16px; height:16px; }
    .ob-topbar-title { font-size:15px; font-weight:800; }
    .ob-container { padding:14px; max-width:520px; margin:0 auto; }
    .ob-tabs { display:flex; gap:8px; margin-bottom:12px; overflow-x:auto; }
    .ob-tab {
        flex-shrink:0; padding:7px 14px; border-radius:999px; font-size:12px; font-weight:700;
        text-decoration:none; color:var(--ocean); background:#fff; border:1.5px solid var(--border);
    }
    .ob-tab.active { background:var(--ocean); border-color:var(--ocean); color:#fff; }
    .ob-section {
        background:#fff; border-radius:12px; padding:14px; margin-bottom:12px;
        box-shadow:0 1px 3px rgba(0,0,0,.06); border:1px solid var(--border);
    }
    .ob-row {
        display:flex; justify-content:space-between; align-items:center; padding:10px;
        background:var(--sky); border-radius:8px; text-decoration:none; color:inherit; margin-bottom:8px;
    }
    .ob-row:last-child { margin-bottom:0; }
    .ob-row-title { font-size:12.5px; font-weight:700; color:var(--text); }
    .ob-row-sub { font-size:11px; color:var(--muted); margin-top:1px; }
    .ob-row-meta { text-align:right; font-size:11px; color:var(--muted); }
    .ob-empty { font-size:12px; color:var(--muted); text-align:center; padding:20px 0; }
    .ob-badge {
        display:inline-block; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700;
    }
    .ob-badge-draft { background:#FEF3C7; color:var(--warning); }
    .ob-badge-confirmed { background:#D1FAE5; color:var(--success); }
    .ob-badge-issued { background:#FEE2E2; color:var(--danger); }
    .ob-badge-partial { background:#FEF3C7; color:var(--warning); }
    .ob-badge-paid { background:#D1FAE5; color:var(--success); }
    .ob-badge-income { background:#D1FAE5; color:var(--success); }
    .ob-badge-expense { background:#FEE2E2; color:var(--danger); }
    .ob-summary { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:14px; }
    .ob-summary-item {
        background:#fff; border:1px solid var(--border); border-radius:10px; padding:10px; text-align:center;
    }
    .ob-summary-label { font-size:9.5px; color:var(--muted); text-transform:uppercase; font-weight:600; }
    .ob-summary-value { font-size:14px; font-weight:800; margin-top:3px; }
    .ob-agenda-date {
        font-size:11px; font-weight:700; color:var(--ocean); margin:14px 0 6px; text-transform:uppercase;
    }
    .ob-agenda-date:first-child { margin-top:0; }
</style>
</head>
<body>

<div class="ob-topbar">
    <a href="<?php echo htmlspecialchars($backUrl); ?>" class="ob-back"><i data-feather="arrow-left"></i></a>
    <div class="ob-topbar-title"><?php echo htmlspecialchars($pageTitle); ?></div>
    <div style="width:30px;"></div>
</div>

<div class="ob-container">
