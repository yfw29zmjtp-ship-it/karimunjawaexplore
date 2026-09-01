<?php
// Grouped deposit display component
// This file only outputs the grouped content - wrapper div is in index.php
if (!isset($depositsByInvestor)) {
    $depositsByInvestor = [];
}
?>

<?php if (!empty($depositsByInvestor)): ?>
<div class="deposits-grouped">
    <?php foreach ($depositsByInvestor as $investorId => $investorData): ?>
    <div class="investor-deposit-group expanded">
        <button class="group-header" onclick="toggleDepositGroup(this)">
            <span class="toggle-icon">▶</span>
            <span class="investor-badge"><?= htmlspecialchars($investorData['name']) ?></span>
            <span class="deposit-count"><?= count($investorData['deposits']) ?> deposit(s)</span>
            <?php $totalDeposit = array_sum(array_column($investorData['deposits'], 'amount')); ?>
            <span class="total-amount">Rp <?= number_format($totalDeposit, 0, ',', '.') ?></span>
        </button>
        <div class="deposit-items">
            <div class="investor-contact">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 0.4rem;">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
                <?= htmlspecialchars($investorData['contact'] ?? '-') ?>
            </div>
            <table class="deposit-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th style="text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($investorData['deposits'] as $deposit): ?>
                    <tr>
                        <td class="deposit-date"><?= date('d M Y H:i', strtotime($deposit['created_at'])) ?></td>
                        <td class="deposit-desc"><?= htmlspecialchars($deposit['description'] ?? 'Capital Deposit') ?></td>
                        <td class="deposit-amount">Rp <?= number_format($deposit['amount'] ?? 0, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-state">
    <p>No deposit history yet</p>
</div>
<?php endif; ?>
