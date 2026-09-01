<!-- Shared container form fields – included by db-containers.php create modal -->
<!-- $defaults array may be passed for pre-fill (edit mode) -->
<?php $d = $defaults ?? []; ?>
<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:12px">
    <div>
        <label class="form-lbl">Container No (Physical)</label>
        <input class="input" name="container_no" value="<?= htmlspecialchars($d['container_no']??'') ?>" placeholder="e.g. TEMU2134567" style="width:100%">
    </div>
    <div>
        <label class="form-lbl">Shipment Date</label>
        <input class="input" type="date" name="shipment_date" value="<?= htmlspecialchars($d['shipment_date']??date('Y-m-d')) ?>" style="width:100%">
    </div>
    <div>
        <label class="form-lbl">Container Type</label>
        <select class="select" name="container_type" style="width:100%">
            <?php foreach(['20ft','40ft','40hc','lcl','fcl'] as $t): ?>
            <option value="<?= $t ?>" <?= ($d['container_type']??'40hc')===$t?'selected':'' ?>><?= strtoupper($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
    <div>
        <label class="form-lbl">Destination Country</label>
        <input class="input" name="destination_country" value="<?= htmlspecialchars($d['destination_country']??'') ?>" placeholder="e.g. Japan" style="width:100%">
    </div>
    <div>
        <label class="form-lbl">Destination Port</label>
        <input class="input" name="destination_port" value="<?= htmlspecialchars($d['destination_port']??'') ?>" placeholder="e.g. Osaka" style="width:100%">
    </div>
    <div>
        <label class="form-lbl">Forwarder</label>
        <input class="input" name="forwarder" value="<?= htmlspecialchars($d['forwarder']??'') ?>" placeholder="Forwarding company name" style="width:100%">
    </div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
    <div>
        <label class="form-lbl">Bill of Lading No</label>
        <input class="input" name="bl_no" value="<?= htmlspecialchars($d['bl_no']??'') ?>" placeholder="BL Number" style="width:100%">
    </div>
    <div>
        <label class="form-lbl">Tracking No</label>
        <input class="input" name="tracking_no" value="<?= htmlspecialchars($d['tracking_no']??'') ?>" placeholder="Tracking / booking no" style="width:100%">
    </div>
    <div>
        <label class="form-lbl">Initial Status</label>
        <select class="select" name="status" style="width:100%">
            <option value="draft" <?= ($d['status']??'')==='draft'?'selected':'' ?>>Draft</option>
            <option value="booked" <?= ($d['status']??'booked')==='booked'?'selected':'' ?>>Booked</option>
            <option value="onboard" <?= ($d['status']??'')==='onboard'?'selected':'' ?>>On Board</option>
            <option value="arrived" <?= ($d['status']??'')==='arrived'?'selected':'' ?>>Arrived</option>
            <option value="closed" <?= ($d['status']??'')==='closed'?'selected':'' ?>>Closed</option>
        </select>
    </div>
</div>
<div>
    <label class="form-lbl">Notes</label>
    <textarea class="input" name="notes" placeholder="Additional notes (optional)" style="width:100%;min-height:56px;resize:vertical"><?= htmlspecialchars($d['notes']??'') ?></textarea>
</div>
