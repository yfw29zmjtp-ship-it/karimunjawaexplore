<?php

/**
 * Sunsea - Database Center (Master Data Hub)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
sunseaEnsureAccommodationSchema($pdo);
sunseaEnsureMasterDataSchema($pdo);

function safeCountTable(PDO $pdo, string $table, string $where = '1=1'): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() === 0) {
            return 0;
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

$stats = [
    'customers' => 0,
    'partners' => 0,
    'guides' => 0,
    'tickets' => 0,
    'caterings' => 0,
    'facilities' => 0,
    'transport' => 0,
    'coordinators' => 0,
];

$stats['customers'] = safeCountTable($pdo, 'customers', 'is_active=1');
$stats['partners'] = safeCountTable($pdo, 'accommodation_partners', 'is_active=1');
$stats['guides'] = safeCountTable($pdo, 'guides', 'is_active=1');
$stats['tickets'] = safeCountTable($pdo, 'tickets', 'is_active=1');
$stats['caterings'] = safeCountTable($pdo, 'caterings', 'is_active=1');
$stats['facilities'] = safeCountTable($pdo, 'facilities', 'is_active=1');
$stats['transport'] = safeCountTable($pdo, 'transport_items', 'is_active=1');
$stats['coordinators'] = safeCountTable($pdo, 'coordinators', 'is_active=1');

$pageTitle = 'Database';
$activePage = 'database';
include 'layout-header.php';
?>

<style>
    .db-page .ss-card {
        padding: 14px 16px;
    }

    .db-page .ss-card-title {
        font-size: 13px;
    }

    .db-page .ss-card-sub {
        font-size: 11px;
    }

    .db-page .ss-stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
    }

    .db-page .ss-stat-card {
        padding: 10px 12px;
        gap: 10px;
    }

    .db-page .ss-stat-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
    }

    .db-page .ss-stat-icon svg {
        width: 15px;
        height: 15px;
    }

    .db-page .ss-stat-value {
        font-size: 16px;
    }

    .db-page .ss-stat-label {
        font-size: 10.5px;
        margin-top: 2px;
    }

    .db-page .ss-btn {
        font-size: 11.5px;
        padding: 6px 10px;
        height: auto;
    }
</style>

<div class="db-page">
<div class="ss-card" style="margin-bottom:14px;">
    <div class="ss-card-header" style="margin-bottom:12px;">
        <div>
            <div class="ss-card-title">Database Master Explore Karimunjawa</div>
            <div class="ss-card-sub">Pusat data referensi untuk operasional booking, quotation, dan invoice</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="partners.php" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="plus" style="width:12px;height:12px;"></i> Tambah Database Baru</a>
        </div>
    </div>

    <div class="ss-stats-grid" style="margin-bottom:0;">
        <div class="ss-stat-card">
            <div class="ss-stat-icon ocean"><i data-feather="users"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['customers']; ?></div>
                <div class="ss-stat-label">Pelanggan Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon cyan"><i data-feather="building"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['partners']; ?></div>
                <div class="ss-stat-label">Hotel/Homestay Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon"><i data-feather="compass"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['guides']; ?></div>
                <div class="ss-stat-label">Guide Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon success"><i data-feather="tickets"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['tickets']; ?></div>
                <div class="ss-stat-label">Tiket Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon warning"><i data-feather="coffee"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['caterings']; ?></div>
                <div class="ss-stat-label">Catering Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon success"><i data-feather="tool"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['facilities']; ?></div>
                <div class="ss-stat-label">Fasilitas Tambahan Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon ocean"><i data-feather="truck"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['transport']; ?></div>
                <div class="ss-stat-label">Transportasi Aktif</div>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon cyan"><i data-feather="user-check"></i></div>
            <div>
                <div class="ss-stat-value"><?php echo $stats['coordinators']; ?></div>
                <div class="ss-stat-label">Koordinator Aktif</div>
            </div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:4px;">Database Harga Hotel & Homestay</div>
        <div class="ss-card-sub" style="margin-bottom:10px;">Master mitra penginapan, tipe kamar, modal, dan harga jual.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="partners.php" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="building" style="width:12px;height:12px;"></i> Kelola Hotel/Homestay</a>
            <a href="partners.php" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="plus" style="width:12px;height:12px;"></i> Tambah Hotel/Homestay</a>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Nama Pelanggan Detail</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Data identitas pelanggan lengkap untuk kebutuhan dokumen.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="customers.php" class="ss-btn ss-btn-primary"><i data-feather="users"></i> Kelola Pelanggan</a>
            <a href="customers.php" class="ss-btn ss-btn-outline"><i data-feather="plus"></i> Tambah Pelanggan</a>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Guide</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Guide darat/laut, status ketersediaan, dan tarif harian.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="guides.php" class="ss-btn ss-btn-primary"><i data-feather="compass"></i> Kelola Guide</a>
            <a href="guides.php" class="ss-btn ss-btn-outline"><i data-feather="plus"></i> Tambah Guide</a>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Tiket</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Tiket kapal, BTN, express, fast boat, dengan harga modal dan jual.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="tickets.php" class="ss-btn ss-btn-primary"><i data-feather="ticket"></i> Kelola Tiket</a>
            <a href="tickets.php" class="ss-btn ss-btn-outline"><i data-feather="plus"></i> Tambah Tiket</a>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Catering</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Master vendor catering, menu paket, dan harga porsi.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="catering.php" class="ss-btn ss-btn-primary"><i data-feather="coffee"></i> Kelola Catering</a>
            <a href="catering.php" class="ss-btn ss-btn-outline"><i data-feather="plus"></i> Tambah Catering</a>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Fasilitas Tambahan</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Peralatan/layanan tambahan untuk booking ecer maupun paket.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="facilities.php" class="ss-btn ss-btn-primary"><i data-feather="tool"></i> Kelola Fasilitas</a>
            <a href="facilities.php" class="ss-btn ss-btn-outline"><i data-feather="plus"></i> Tambah Fasilitas</a>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Transportasi Karimunjawa</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Mobil/motor jemput-antar pelabuhan, trip darat, dan trip laut. Harga bisa diubah manual.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="transport.php" class="ss-btn ss-btn-primary"><i data-feather="truck"></i> Kelola Transportasi</a>
            <a href="transport.php" class="ss-btn ss-btn-outline"><i data-feather="plus"></i> Tambah Transportasi</a>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:6px;">Database Koordinator</div>
        <div class="ss-card-sub" style="margin-bottom:12px;">Master koordinator trip lapangan untuk penugasan booking.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="coordinators.php" class="ss-btn ss-btn-primary"><i data-feather="user-check"></i> Kelola Koordinator</a>
            <a href="coordinators.php" class="ss-btn ss-btn-outline"><i data-feather="plus"></i> Tambah Koordinator</a>
        </div>
    </div>
</div>
</div>

<?php include 'layout-footer.php';
