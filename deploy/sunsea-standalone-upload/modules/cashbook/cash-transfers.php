<?php

/**
 * Ringkasan Setor Tunai
 * Tracking transfer antar rekening kas, dapat diarsipkan
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// Get master DB untuk cash_transfers
try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    $masterDb = $db->getConnection();
}

$businessId = getMasterBusinessId();
$currentUser = $auth->getCurrentUser();

// Auto-migrate: link column so each cash_transfers row knows which cash_book
// row (business DB) it created, so delete/backfill can find & remove it.
try {
    $masterDb->exec("ALTER TABLE cash_transfers ADD COLUMN cash_book_id INT NULL AFTER archived_by");
} catch (Exception $e) { /* column already exists */
}

// Auto-drop FK constraint on cash_book.created_by (references users in master DB, not
// business DB) - same fix already applied in add.php/index.php - needed here too since
// we insert/delete cash_book rows directly from this page (backfill + delete below).
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` DROP FOREIGN KEY `cash_book_ibfk_3`");
} catch (Exception $e) { /* already dropped or doesn't exist */
}

// Auto-fix: allow NULL division_id/category_id - Setor Tunai (cash_transfer) rows
// have no division/category since they're an internal cash<->bank transfer, not a
// real income/expense transaction. Original schema had these NOT NULL.
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `division_id` INT NULL");
} catch (Exception $e) { /* ignore */
}
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `category_id` INT NULL");
} catch (Exception $e) { /* ignore */
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Archive a cash transfer
    if ($_GET['ajax'] === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $isArchive = intval($_POST['is_archived'] ?? 0);

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            exit;
        }

        try {
            if ($isArchive) {
                // Archive
                $stmt = $masterDb->prepare("UPDATE cash_transfers SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ? AND business_id = ?");
                $stmt->execute([$_SESSION['user_id'], $id, $businessId]);
                echo json_encode(['success' => true, 'message' => 'Setor tunai berhasil diarsipkan']);
            } else {
                // Unarchive
                $stmt = $masterDb->prepare("UPDATE cash_transfers SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ? AND business_id = ?");
                $stmt->execute([$id, $businessId]);
                echo json_encode(['success' => true, 'message' => 'Setor tunai berhasil di-unarchive']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Delete a cash transfer entirely: reverses BOTH sides of the transfer
    // (adds amount back to the source cash account, removes it from the
    // destination bank account) and removes the linked cash_book row so
    // Buku Kas / Cash Available also revert correctly.
    if ($_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->canDelete('cashbook')) {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki izin untuk menghapus.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            exit;
        }

        try {
            $stmt = $masterDb->prepare("SELECT * FROM cash_transfers WHERE id = ? AND business_id = ?");
            $stmt->execute([$id, $businessId]);
            $tr = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tr) {
                echo json_encode(['success' => false, 'message' => 'Data setor tunai tidak ditemukan']);
                exit;
            }

            $masterDb->beginTransaction();

            // Reverse: give the amount back to the source cash account...
            $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")
                ->execute([$tr['amount'], $tr['cash_account_id']]);
            // ...and remove it from the destination bank account
            $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?")
                ->execute([$tr['amount'], $tr['bank_account_id']]);

            // Remove the linked cash_book tracking row (if any)
            if (!empty($tr['cash_book_id'])) {
                try {
                    $db->delete('cash_book', 'id = :id', ['id' => $tr['cash_book_id']]);
                } catch (Exception $cbEx) {
                    error_log("cash-transfers.php delete: failed removing linked cash_book row: " . $cbEx->getMessage());
                }
            }

            $masterDb->prepare("DELETE FROM cash_transfers WHERE id = ?")->execute([$id]);

            $masterDb->commit();
            echo json_encode(['success' => true, 'message' => '✅ Setor tunai dihapus & saldo dikembalikan']);
        } catch (Exception $e) {
            if ($masterDb->inTransaction()) {
                $masterDb->rollBack();
            }
            error_log("cash-transfers.php delete error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Get filters
$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$filterAccount = $_GET['account'] ?? '';

// Build query
// NOTE: all columns here must be qualified with ct. because the transfers
// query below JOINs cash_accounts (aliased ca_cash/ca_bank), which also has
// a business_id column -> unqualified "business_id" is ambiguous (SQLSTATE 23000).
$where = ['ct.business_id = ?'];
$params = [$businessId];

if (!$showArchived) {
    $where[] = 'ct.is_archived = 0';
}

if ($dateFrom) {
    $where[] = 'ct.transfer_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = 'ct.transfer_date <= ?';
    $params[] = $dateTo;
}

if ($filterAccount) {
    $where[] = '(ct.cash_account_id = ? OR ct.bank_account_id = ?)';
    $params[] = $filterAccount;
    $params[] = $filterAccount;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$pageError = '';
$transfers = [];
$accounts = [];
$totalAmount = 0;
$totalOperationalExpense = 0;
$netSetorAmount = 0;
$expenseDetails = [];

// Backfill: older cash_transfers rows (created before the cash_book link
// existed) have no matching row in Buku Kas / no cash_book_id. Create the
// missing cash_book entry now so "Cash Available" + the ledger reflect them,
// then remember the link so future page loads / delete skip it.
try {
    $missingStmt = $masterDb->prepare("
        SELECT ct.*, ca_cash.account_name as cash_name, ca_bank.account_name as bank_name
        FROM cash_transfers ct
        LEFT JOIN cash_accounts ca_cash ON ct.cash_account_id = ca_cash.id
        LEFT JOIN cash_accounts ca_bank ON ct.bank_account_id = ca_bank.id
        WHERE ct.business_id = ? AND (ct.cash_book_id IS NULL OR ct.cash_book_id = 0)
    ");
    $missingStmt->execute([$businessId]);
    $missingTransfers = $missingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($missingTransfers as $mt) {
        // Description was stored as "[Penyetor] rest of text..." - extract penyetor name
        $rawDesc = $mt['description'] ?? '';
        $penyetor = '';
        if (preg_match('/^\[(.*?)\]\s*(.*)$/', $rawDesc, $m)) {
            $penyetor = $m[1];
            $rawDesc = $m[2];
        }
        $cbData = [
            'transaction_date' => $mt['transfer_date'],
            'transaction_time' => $mt['transfer_time'],
            'division_id' => null,
            'category_id' => null,
            'transaction_type' => 'expense',
            'amount' => $mt['amount'],
            'description' => "Pemindahan Uang / Setoran Harian ke " . ($mt['bank_name'] ?: 'rekening bank')
                . ($penyetor ? " - Penyetor: {$penyetor}" : '') . ($rawDesc ? " - {$rawDesc}" : ''),
            'payment_method' => 'cash',
            'cash_account_id' => $mt['cash_account_id'],
            'created_by' => $mt['created_by'],
            'source_type' => 'cash_transfer',
            'is_editable' => 0
        ];
        if ($db->insert('cash_book', $cbData)) {
            $newCashBookId = $db->getConnection()->lastInsertId();
            $masterDb->prepare("UPDATE cash_transfers SET cash_book_id = ? WHERE id = ?")
                ->execute([$newCashBookId, $mt['id']]);
        }
    }
} catch (Exception $e) {
    error_log("cash-transfers.php: backfill cash_book failed: " . $e->getMessage());
}

try {
    // Get transfers
    // NOTE: users table lives in the per-business database (via $db), NOT the
    // master DB ($masterDb) where cash_transfers/cash_accounts live. Joining
    // users here would fail/return nothing since the two are separate databases.
    // Resolve user names separately below using the business DB connection.
    $stmt = $masterDb->prepare("
        SELECT 
            ct.id, ct.amount, ct.transfer_date, ct.transfer_time,
            ct.description, ct.created_by, ct.is_archived, ct.archived_at, ct.archived_by,
            ct.cash_account_id, ct.bank_account_id,
            ca_cash.account_name as cash_account_name,
            ca_bank.account_name as bank_account_name
        FROM cash_transfers ct
        LEFT JOIN cash_accounts ca_cash ON ct.cash_account_id = ca_cash.id
        LEFT JOIN cash_accounts ca_bank ON ct.bank_account_id = ca_bank.id
        $whereClause
        ORDER BY ct.transfer_date DESC, ct.transfer_time DESC
    ");

    $stmt->execute($params);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve created_by / archived_by user names from the business DB (not master DB)
    $userIds = [];
    foreach ($transfers as $t) {
        if (!empty($t['created_by'])) $userIds[] = (int)$t['created_by'];
        if (!empty($t['archived_by'])) $userIds[] = (int)$t['archived_by'];
    }
    $userIds = array_values(array_unique($userIds));
    $userNames = [];
    if (!empty($userIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $uStmt = $db->getConnection()->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders)");
            $uStmt->execute($userIds);
            foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $userNames[$u['id']] = $u['full_name'];
            }
        } catch (Exception $e) {
            // Non-fatal: just show "Sistem" if user lookup fails
            error_log("cash-transfers.php: user lookup failed: " . $e->getMessage());
        }
    }
    foreach ($transfers as &$t) {
        $t['created_user'] = $userNames[$t['created_by']] ?? null;
        $t['archived_user'] = $userNames[$t['archived_by']] ?? null;
    }
    unset($t);

    // Get all cash accounts untuk dropdown
    $accStmt = $masterDb->prepare("
        SELECT id, account_name, account_type 
        FROM cash_accounts 
        WHERE business_id = ? AND is_active = 1
        ORDER BY account_type, account_name
    ");
    $accStmt->execute([$businessId]);
    $accounts = $accStmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary (list total - date-filtered)
    foreach ($transfers as $t) {
        $totalAmount += floatval($t['amount']);
    }

    // All-time total setor (not affected by date filter) - used for summary card
    $totalSetorAllTime = 0;
    $stmtAll = $masterDb->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_transfers
        WHERE business_id = ? AND is_archived = 0
    ");
    $stmtAll->execute([$businessId]);
    $totalSetorAllTime = (float)$stmtAll->fetchColumn();

    // Build account map for readable expense details
    $accountNameById = [];
    foreach ($accounts as $acc) {
        $accountNameById[(int)$acc['id']] = $acc['account_name'];
    }

    // Get ONLY the bank accounts that are DESTINATION of Setor Tunai transfers (rekening operasional).
    // DO NOT use all account_type='bank' — that would include Rekening Kas Besar which is NOT
    // the same as Rekening Operasional. Only accounts that appear in cash_transfers.bank_account_id
    // are the true "operational bank accounts" targeted by Setor Tunai.
    // We query without date filter so this list is always populated regardless of the list's date range.
    $bankAccountIds = [];
    $opBankStmt = $masterDb->prepare("
        SELECT DISTINCT ct.bank_account_id, ca.account_name
        FROM cash_transfers ct
        JOIN cash_accounts ca ON ct.bank_account_id = ca.id
        WHERE ct.business_id = ? AND ct.bank_account_id IS NOT NULL
    ");
    $opBankStmt->execute([$businessId]);
    foreach ($opBankStmt->fetchAll(PDO::FETCH_ASSOC) as $ba) {
        $bankAccountIds[] = (int)$ba['bank_account_id'];
        // Ensure these accounts are in the name map
        $accountNameById[(int)$ba['bank_account_id']] = $ba['account_name'];
    }
    $bankAccountIds = array_values(array_unique($bankAccountIds));

    // Calculate expense usage from those operational bank accounts
    if (!empty($bankAccountIds)) {
        $ph = implode(',', array_fill(0, count($bankAccountIds), '?'));
        $expenseSql = "
            SELECT cb.id, cb.transaction_date, cb.transaction_time, cb.amount,
                   cb.description, cb.payment_method, cb.cash_account_id, cb.created_by
            FROM cash_book cb
            WHERE cb.transaction_type = 'expense'
              AND cb.cash_account_id IN ($ph)
              AND (cb.source_type IS NULL OR cb.source_type <> 'cash_transfer')
        ";

        $expenseParams = $bankAccountIds;
        if ($dateFrom) {
            $expenseSql .= " AND cb.transaction_date >= ?";
            $expenseParams[] = $dateFrom;
        }
        if ($dateTo) {
            $expenseSql .= " AND cb.transaction_date <= ?";
            $expenseParams[] = $dateTo;
        }
        $expenseSql .= " ORDER BY cb.transaction_date DESC, cb.transaction_time DESC, cb.id DESC";

        $expStmt = $db->getConnection()->prepare($expenseSql);
        $expStmt->execute($expenseParams);
        $expenseRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

        $expenseUserIds = [];
        foreach ($expenseRows as $er) {
            if (!empty($er['created_by'])) {
                $expenseUserIds[] = (int)$er['created_by'];
            }
        }
        $expenseUserIds = array_values(array_unique($expenseUserIds));
        $expenseUserNames = [];
        if (!empty($expenseUserIds)) {
            $uph = implode(',', array_fill(0, count($expenseUserIds), '?'));
            $euStmt = $db->getConnection()->prepare("SELECT id, full_name FROM users WHERE id IN ($uph)");
            $euStmt->execute($expenseUserIds);
            foreach ($euStmt->fetchAll(PDO::FETCH_ASSOC) as $eu) {
                $expenseUserNames[(int)$eu['id']] = $eu['full_name'];
            }
        }

        foreach ($expenseRows as $er) {
            $totalOperationalExpense += (float)$er['amount'];
            $expenseDetails[] = [
                'id' => (int)$er['id'],
                'date' => $er['transaction_date'],
                'time' => $er['transaction_time'],
                'amount' => (float)$er['amount'],
                'description' => $er['description'] ?? '',
                'payment_method' => $er['payment_method'] ?? '',
                'account_name' => $accountNameById[(int)($er['cash_account_id'] ?? 0)] ?? ('Akun #' . (int)($er['cash_account_id'] ?? 0)),
                'input_by' => $expenseUserNames[(int)($er['created_by'] ?? 0)] ?? 'Sistem'
            ];
        }
    }

    $netSetorAmount = $totalAmount - $totalOperationalExpense;

    // Gabungkan setor tunai + pengeluaran operasional ke satu daftar kronologis
    $allTransactions = [];
    foreach ($transfers as $t) {
        $allTransactions[] = [
            'type'     => 'setor',
            'sort_key' => $t['transfer_date'] . ' ' . $t['transfer_time'],
            'data'     => $t
        ];
    }
    foreach ($expenseDetails as $e) {
        $allTransactions[] = [
            'type'     => 'expense',
            'sort_key' => $e['date'] . ' ' . $e['time'],
            'data'     => $e
        ];
    }
    usort($allTransactions, function ($a, $b) {
        return strcmp($b['sort_key'], $a['sort_key']);
    });

} catch (Exception $e) {
    error_log("cash-transfers.php: fatal query error: " . $e->getMessage());
    $pageError = 'Terjadi error saat memuat data setor tunai: ' . $e->getMessage();
}

$pageTitle = '🏦 Ringkasan Setor Tunai';
$pageSubtitle = 'Tracking transfer uang tunai ke rekening operasional, dapat diarsipkan';

include '../../includes/header.php';
?>

<style>
    .tx-row {
        display: grid;
        grid-template-columns: 38px 1fr auto;
        gap: 0.75rem;
        align-items: center;
        padding: 0.6rem 0.9rem;
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        transition: box-shadow 0.15s;
    }
    .tx-row:hover {
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .tx-row.setor  { border-left: 3px solid #0284c7; }
    .tx-row.expense{ border-left: 3px solid #dc2626; }
    .tx-icon {
        width: 34px; height: 34px; border-radius: 7px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.05rem;
    }
    .tx-icon.setor  { background: rgba(2,132,199,0.12); }
    .tx-icon.expense{ background: rgba(220,38,38,0.09); }
    .tx-title {
        font-size: 0.82rem; font-weight: 600;
        color: var(--text-primary);
        display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;
    }
    .tx-meta {
        font-size: 0.71rem; color: var(--text-muted); margin-top: 0.15rem;
    }
    .tx-amount-setor  { font-size: 0.88rem; font-weight: 700; color: #0284c7; white-space: nowrap; }
    .tx-amount-expense{ font-size: 0.88rem; font-weight: 700; color: #b91c1c; white-space: nowrap; }
    .tx-actions {
        display: flex; gap: 0.3rem; justify-content: flex-end; margin-top: 0.35rem;
    }
    .tx-btn {
        padding: 0.2rem 0.45rem; font-size: 0.68rem;
        border: 1px solid var(--bg-tertiary);
        border-radius: 4px; cursor: pointer;
        background: var(--bg-secondary);
    }
    .tx-btn-del {
        padding: 0.2rem 0.45rem; font-size: 0.68rem;
        border: 1px solid #fca5a5; border-radius: 4px; cursor: pointer;
        background: #fee2e2; color: #b91c1c;
    }

    .filter-card {
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }

    .filter-group label {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-muted);
    }

    .filter-group input,
    .filter-group select {
        height: 36px;
        font-size: 0.813rem;
        border: 1px solid var(--bg-tertiary);
        border-radius: 6px;
        padding: 0.4rem 0.6rem;
    }

    .summary-box {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        border: 1px solid #7dd3fc;
        border-radius: 10px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }

    .summary-item {
        text-align: center;
    }

    .summary-label {
        font-size: 0.75rem;
        color: #0c4a6e;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 0.25rem;
    }

    .summary-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0284c7;
    }

    .expense-summary-note {
        margin-bottom: 1rem;
        padding: 0.75rem 0.9rem;
        background: #fff7ed;
        border: 1px solid #fdba74;
        border-radius: 8px;
        color: #9a3412;
        font-size: 0.78rem;
    }

    .expense-detail-list {
        margin-top: 1rem;
        border-top: 1px dashed var(--bg-tertiary);
    }

    .expense-detail-row {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 0.75rem;
        align-items: center;
        padding: 0.7rem 0;
        border-bottom: 1px solid var(--bg-secondary);
    }

    .expense-detail-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.2rem;
    }

    .expense-detail-amount {
        font-size: 0.9rem;
        font-weight: 700;
        color: #b91c1c;
        white-space: nowrap;
    }
</style>

<div style="max-width: 1000px; margin: 0 auto;">
    <!-- Header -->
    <div class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; border-left: 4px solid #0284c7;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #0284c7, #0369a1); display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                🏦
            </div>
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700; margin: 0; color: var(--text-primary);">Ringkasan Setor Tunai</h1>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Tracking transfer ke rekening operasional</p>
            </div>
        </div>
        <a href="add.php" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem;">
            <i data-feather="plus" style="width: 14px; height: 14px;"></i> Setor Tunai Baru
        </a>
    </div>

    <!-- Quick toggle: Aktif <-> Arsipan (clears date/account filters so archived
         items from ANY date are shown, since the date filter otherwise defaults
         to hiding items outside the selected range) -->
    <div style="margin-bottom: 1rem;">
        <?php if (!$showArchived): ?>
            <a href="?archived=1" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem;">
                📦 Lihat Arsipan
            </a>
        <?php else: ?>
            <a href="?archived=0" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem;">
                👁️ Lihat Aktif
            </a>
            <span style="font-size: 0.8rem; color: var(--text-muted); margin-left: 0.5rem;">Sedang menampilkan data yang diarsipkan. Gunakan tombol "🗑️ Hapus" di bawah untuk menghapus permanen.</span>
        <?php endif; ?>
    </div>

    <?php if ($pageError): ?>
        <div class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem; border-left: 4px solid #dc2626; background: #fef2f2; color: #991b1b;">
            ⚠️ <?php echo htmlspecialchars($pageError); ?>
        </div>
    <?php endif; ?>

    <!-- Summary: always show, uses all-time totals so filter does not hide the balance -->
    <?php
    $netAllTime = $totalSetorAllTime - $totalOperationalExpense;
    ?>
    <div class="summary-box">
        <div class="summary-item">
            <div class="summary-label">Total Setor (Semua)</div>
            <div class="summary-value">Rp <?php echo number_format($totalSetorAllTime, 0, ',', '.'); ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Pengeluaran Rek. Operasional</div>
            <div class="summary-value" style="color:#b91c1c;">Rp <?php echo number_format($totalOperationalExpense, 0, ',', '.'); ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Saldo Setor Bersih</div>
            <div class="summary-value" style="color:<?php echo $netAllTime < 0 ? '#b91c1c' : '#0f766e'; ?>;">Rp <?php echo number_format($netAllTime, 0, ',', '.'); ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Transaksi Setor (Perioda)</div>
            <div class="summary-value"><?php echo count($transfers); ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Status</div>
            <div class="summary-value"><?php echo $showArchived ? 'Arsipan' : 'Aktif'; ?></div>
        </div>
    </div>
    <div class="expense-summary-note">
        Ringkasan ini memperhitungkan semua pengeluaran dari rekening bank operasional hasil setoran tunai (tidak termasuk transaksi internal Setor Tunai). Detail di bawah mengikuti filter tanggal.
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" style="display: contents;">
            <div class="filter-group">
                <label>Dari Tanggal</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="filter-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="filter-group">
                <label>Rekening</label>
                <select name="account">
                    <option value="">-- Semua Rekening --</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($filterAccount == $acc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($acc['account_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="archived">
                    <option value="0" <?php echo !$showArchived ? 'selected' : ''; ?>>Aktif</option>
                    <option value="1" <?php echo $showArchived ? 'selected' : ''; ?>>Arsipan</option>
                </select>
            </div>
            <div class="filter-group">
                <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                    <i data-feather="filter" style="width: 14px; height: 14px; margin-right: 0.25rem;"></i> Filter
                </button>
            </div>
            <div class="filter-group">
                <a href="<?php echo $_SERVER['REQUEST_URI']; ?>" class="btn-small" style="text-align: center; text-decoration: none;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Unified Transaction List (Setor Tunai + Pengeluaran — sorted by date) -->
    <div class="card" style="padding: 0.9rem 1rem;">
        <?php if (empty($allTransactions)): ?>
            <div style="text-align:center;padding:2.5rem 1rem;color:var(--text-muted);">
                <div style="font-size:2.5rem;margin-bottom:0.6rem;">🏦</div>
                <div style="font-size:0.88rem;font-weight:600;margin-bottom:0.3rem;">Belum ada transaksi</div>
                <div style="font-size:0.78rem;">Gunakan tombol "Setor Tunai Baru" untuk mencatat pertama kali.</div>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:0.4rem;">
            <?php foreach ($allTransactions as $tx):
                $isSetor = $tx['type'] === 'setor';
                $t       = $tx['data'];
                $txDate  = $isSetor ? $t['transfer_date'] : $t['date'];
                $txTime  = $isSetor ? $t['transfer_time'] : $t['time'];
                $cleanDesc = $t['description'] ?? '';
                if (!$isSetor) {
                    $cleanDesc = trim(str_replace(['[Rekening Operasional]','[Kas Besar]','[Petty Cash]'], '', $cleanDesc));
                }
            ?>
            <div class="tx-row <?php echo $isSetor ? 'setor' : 'expense'; ?>"
                 id="transfer-<?php echo $isSetor ? $t['id'] : ('e'.$t['id']); ?>">

                <!-- Ikon -->
                <div class="tx-icon <?php echo $isSetor ? 'setor' : 'expense'; ?>">
                    <?php echo $isSetor ? '💰' : '📤'; ?>
                </div>

                <!-- Keterangan -->
                <div>
                    <div class="tx-title">
                    <?php if ($isSetor): ?>
                        <span>Setor Tunai</span>
                        <span style="font-weight:400;color:var(--text-secondary);">
                            <?php echo htmlspecialchars($t['cash_account_name']); ?>
                            → <?php echo htmlspecialchars($t['bank_account_name']); ?>
                        </span>
                        <?php if ($t['is_archived']): ?>
                            <span style="font-size:0.6rem;background:#fef3c7;color:#92400e;padding:0.1rem 0.35rem;border-radius:3px;font-weight:700;">ARSIP</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span><?php echo htmlspecialchars($cleanDesc ?: '-'); ?></span>
                        <span style="font-size:0.62rem;background:#fef3c7;color:#b45309;border:1px solid #fcd34d;border-radius:3px;padding:0.1rem 0.35rem;font-weight:700;">🏦 REK. OPS</span>
                    <?php endif; ?>
                    </div>
                    <div class="tx-meta">
                        📅 <?php echo date('d M Y', strtotime($txDate)); ?>
                        &nbsp;🕒 <?php echo date('H:i', strtotime($txTime)); ?>
                        <?php if ($isSetor): ?>
                            &nbsp;|&nbsp;👤 <?php echo htmlspecialchars($t['created_user'] ?? 'Sistem'); ?>
                            <?php if (!empty($t['description'])): ?>
                                &nbsp;| <i style="color:var(--text-secondary);">&ldquo;<?php echo htmlspecialchars($t['description']); ?>&rdquo;</i>
                            <?php endif; ?>
                            <?php if ($t['is_archived'] && !empty($t['archived_user'])): ?>
                                &nbsp;| Arsip: <?php echo htmlspecialchars($t['archived_user']); ?> <?php echo date('d/m/y H:i', strtotime($t['archived_at'])); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            &nbsp;|&nbsp;🏦 <?php echo htmlspecialchars($t['account_name']); ?>
                            &nbsp;|&nbsp;👤 <?php echo htmlspecialchars($t['input_by']); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Nominal + Aksi -->
                <div style="text-align:right;min-width:105px;">
                    <div class="<?php echo $isSetor ? 'tx-amount-setor' : 'tx-amount-expense'; ?>">
                        <?php echo $isSetor ? '+' : '&minus;'; ?>Rp <?php echo number_format($t['amount'], 0, ',', '.'); ?>
                    </div>
                    <?php if ($isSetor): ?>
                    <div class="tx-actions">
                        <button class="tx-btn"
                            onclick="toggleArchive(<?php echo $t['id']; ?>, <?php echo $t['is_archived'] ? '0' : '1'; ?>)"
                            style="color:<?php echo $t['is_archived'] ? '#059669' : '#0284c7'; ?>"
                            title="<?php echo $t['is_archived'] ? 'Batalkan arsip' : 'Arsipkan'; ?>">
                            <?php echo $t['is_archived'] ? '↩' : '📦'; ?>
                        </button>
                        <?php if ($auth->canDelete('cashbook')): ?>
                        <button class="tx-btn-del" onclick="deleteTransfer(<?php echo $t['id']; ?>)" title="Hapus">🗑</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <a href="index.php" class="btn btn-secondary" style="margin-top: 1.5rem; text-decoration: none; display: inline-block; padding: 0.625rem 1.25rem; font-size: 0.875rem;">
        ← Kembali ke Buku Kas
    </a>
</div>

<script>
    feather.replace();

    function toggleArchive(id, isArchive) {
        const action = isArchive ? 'Arsipkan' : 'Batalkan arsip';

        if (!confirm(`${action} setor tunai ini?` + (isArchive ? '\n\n(Data TIDAK dihapus - hanya disembunyikan dari daftar "Aktif". Bisa dilihat lagi lewat filter "Arsipan" di atas.)' : ''))) return;

        const formData = new FormData();
        formData.append('id', id);
        formData.append('is_archived', isArchive);

        fetch('cash-transfers.php?ajax=archive', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (isArchive) {
                        alert('✅ Berhasil diarsipkan.\n\nData TIDAK hilang - ganti filter status di atas ke "Arsipan" untuk melihatnya kembali, atau gunakan tombol Hapus jika memang ingin menghapus permanen.');
                    }
                    // Reload page
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }

    function deleteTransfer(id) {
        if (!confirm('Hapus setor tunai ini? Saldo kas & bank akan dikembalikan seperti semula. Tindakan ini tidak bisa dibatalkan.')) return;

        const formData = new FormData();
        formData.append('id', id);

        fetch('cash-transfers.php?ajax=delete', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }
</script>

<?php include '../../includes/footer.php'; ?>