// Block 2: All function definitions — no PHP output, always safe to parse
console.error('[hotel-services] BLOCK2 RUNNING - v20260729')
window._hsBlock2Loaded = true
const SVC_KEYS = window.SVC_KEYS || []
const SVC_LABELS = window.SVC_LABELS || []
const CATALOG_DATA = window.CATALOG_DATA || {}
const RENTAL_MOTORS = window.RENTAL_MOTORS || []
const RENTAL_CARS = window.RENTAL_CARS || []
let TRIP_GUIDES = window.TRIP_GUIDES || []

// ── Guest mode ────────────────────────────────────────────────────────────────
function setGuestMode (mode) {
  const ih = mode === 'inhouse'
  document.getElementById('inhouseSection').style.display = ih ? '' : 'none'
  document.getElementById('manualSection').style.display = ih ? 'none' : ''
  document.getElementById('btnInhouse').classList.toggle('active', ih)
  document.getElementById('btnManual').classList.toggle('active', !ih)
  if (!ih) {
    document.getElementById('fBookingId').value = ''
  }
}

function fillFromInhouse () {
  const sel = document.getElementById('fGuestSelect')
  const opt = sel.options[sel.selectedIndex]
  document.getElementById('fBookingId').value = opt.value || ''
  document.getElementById('fPhone').value = opt.dataset.phone || ''
  document.getElementById('fRoom').value = opt.dataset.room || ''
}

function getGuestName () {
  const ih = document.getElementById('inhouseSection').style.display !== 'none'
  if (ih) {
    const sel = document.getElementById('fGuestSelect')
    return sel.options[sel.selectedIndex].dataset.name || ''
  }
  return document.getElementById('fGuestName').value.trim()
}

// ── Items ─────────────────────────────────────────────────────────────────────
let rowCnt = 0

function buildSvcOpts (selected) {
  return SVC_KEYS.map(
    (k, i) =>
      `<option value="${k}" ${k === selected ? 'selected' : ''}>${
        SVC_LABELS[i]
      }</option>`
  ).join('')
}

function buildRentalAssetOpts (items, selected, motorSource = '') {
  let html = '<option value="">Pilih armada...</option>'
  items.forEach(item => {
    let include = true
    if (motorSource === 'hotel') {
      include = !item.partner_owner || item.partner_owner.trim() === ''
    } else if (motorSource === 'external') {
      include = item.partner_owner && item.partner_owner.trim() !== ''
    }
    if (include) {
      html += `<option value="${item.id}" data-rate="${item.daily_rate}" ${
        String(item.id) === String(selected || '') ? 'selected' : ''
      }>${item.label}</option>`
    }
  })
  return html
}

function buildMotorSourceOpts (selected) {
  return `
    <option value="">🏍️ Pilih sumber motor</option>
    <option value="hotel" ${
      selected === 'hotel' ? 'selected' : ''
    }>🏨 Motor Hotel</option>
    <option value="external" ${
      selected === 'external' ? 'selected' : ''
    }>🤝 Motor Luar (Mitra)</option>
  `
}

function buildCatalogPaketOpts (svc, selectedIdx) {
  const items = CATALOG_DATA[svc] || []
  const placeholder =
    svc === 'car_rental'
      ? '── Pilih jenis layanan ──'
      : '── Harga dari Armada ──'
  let html = `<option value="">${placeholder}</option>`
  items.forEach((item, i) => {
    html +=
      `<option value="${i}"${
        String(i) === String(selectedIdx) ? ' selected' : ''
      }>` +
      `${item.name} – Rp ${Math.round(item.price).toLocaleString(
        'id-ID'
      )}</option>`
  })
  return html
}

function isRentalService (svc) {
  return svc === 'motor_rental' || svc === 'car_rental'
}

function isNarayanaTrip (svc) {
  return svc === 'narayana_trip'
}

function buildGuideOpts (selected) {
  if (
    (!TRIP_GUIDES || !TRIP_GUIDES.length) &&
    document.getElementById('guideBody')
  ) {
    syncTripGuidesFromTable()
  }
  let html = '<option value="">Pilih guide...</option>'
  TRIP_GUIDES.forEach(g => {
    html += `<option value="${g.id}" ${
      String(g.id) === String(selected || '') ? 'selected' : ''
    }>${g.name}</option>`
  })
  return html
}

function syncTripGuidesFromTable () {
  const body = document.getElementById('guideBody')
  if (!body) return
  const rows = [...body.querySelectorAll('tr[id^="gtr"]')]
  TRIP_GUIDES = rows
    .map(row => {
      const idText = (row.id || '').replace('gtr', '')
      const id = parseInt(idText, 10)
      const name = row.querySelector('.gName')?.value?.trim() || ''
      const phone = row.querySelector('.gPhone')?.value?.trim() || ''
      const sortOrder = parseInt(row.querySelector('.gSort')?.value || '0', 10)
      if (!id || !name) return null
      return {
        id,
        name,
        phone,
        sort_order: Number.isFinite(sortOrder) ? sortOrder : 0
      }
    })
    .filter(Boolean)
    .sort((a, b) => {
      if ((a.sort_order || 0) !== (b.sort_order || 0)) {
        return (a.sort_order || 0) - (b.sort_order || 0)
      }
      return String(a.name || '').localeCompare(String(b.name || ''), 'id')
    })
}

function refreshGuideDropdowns () {
  document.querySelectorAll('.iGuide').forEach(sel => {
    const current = sel.value
    sel.innerHTML = buildGuideOpts(current)
  })
}

// ── Driver / partner vehicle payment (car_rental, airport_drop, harbor_drop) ──
function usesDriverPayment (svc) {
  return svc === 'car_rental' || svc === 'airport_drop' || svc === 'harbor_drop'
}

function getDriverCarId (tr, svc) {
  if (svc === 'car_rental')
    return parseInt(tr.querySelector('.iAsset')?.value || '0', 10)
  if (svc === 'airport_drop' || svc === 'harbor_drop')
    return parseInt(tr.querySelector('.iDriverCar')?.value || '0', 10)
  return 0
}

function updateDriverExtra (tr) {
  const svc = tr.querySelector('.iSvc').value
  const wrap = tr.querySelector('.hs-driver-extra')
  if (!wrap) return
  const show = usesDriverPayment(svc)
  wrap.style.display = show ? '' : 'none'
  const carRow = tr.querySelector('.iDriverCarRow')
  if (carRow) {
    const showCarPicker = svc === 'airport_drop' || svc === 'harbor_drop'
    carRow.style.display = showCarPicker ? '' : 'none'
    const driverCarSelect = tr.querySelector('.iDriverCar')
    if (driverCarSelect && showCarPicker) {
      driverCarSelect.innerHTML = buildRentalAssetOpts(
        RENTAL_CARS,
        driverCarSelect.value
      )
    }
  }
  if (!show) {
    const chk = tr.querySelector('.iNeedsDriver')
    if (chk) chk.checked = false
    const commWrap = tr.querySelector('.iCommWrap')
    if (commWrap) commWrap.style.display = 'none'
  }
}

function prefillDriverCommission (tr) {
  const svc = tr.querySelector('.iSvc').value
  const typeSel = tr.querySelector('.iCommType')
  const valInput = tr.querySelector('.iCommValue')
  if (!typeSel || !valInput) return

  const qty = parseFloat(tr.querySelector('.iQty')?.value) || 1
  const unitPrice = parseFloat(tr.querySelector('.iPrice')?.value) || 0

  // Priority 1: catalog-defined driver rate (airport/harbor drop etc.)
  const catalogDriverRate = parseFloat(tr.dataset.catalogDriverRate || 0)
  if (catalogDriverRate > 0) {
    typeSel.value = 'nominal'
    valInput.value = Math.max(0, (unitPrice - catalogDriverRate) * qty)
    return
  }

  // Priority 2: per-car driver_daily_rate (car_rental)
  const carId = getDriverCarId(tr, svc)
  const car = RENTAL_CARS.find(c => String(c.id) === String(carId))
  if (!car) return
  if (car.driver_daily_rate > 0) {
    typeSel.value = 'nominal'
    valInput.value = Math.max(0, (unitPrice - car.driver_daily_rate) * qty)
  } else if (!valInput.value || parseFloat(valInput.value) === 0) {
    typeSel.value = car.commission_type || 'percent'
    valInput.value =
      car.commission_type === 'nominal'
        ? car.commission_nominal || 0
        : car.commission_pct || 0
  }
}

function onDriverCarChange (id) {
  const tr = document.getElementById(id)
  if (!tr) return
  const chk = tr.querySelector('.iNeedsDriver')
  if (chk && chk.checked) prefillDriverCommission(tr)
}

function onNeedsDriverChange (id) {
  const tr = document.getElementById(id)
  if (!tr) return
  const checked = tr.querySelector('.iNeedsDriver').checked
  const commWrap = tr.querySelector('.iCommWrap')
  if (commWrap) commWrap.style.display = checked ? 'flex' : 'none'
  if (checked) prefillDriverCommission(tr)
}

function onPaketChange (id) {
  const tr = document.getElementById(id)
  if (!tr) return
  const svc = tr.querySelector('.iSvc').value
  const paketSel = tr.querySelector('.iPaket')
  const val = paketSel?.value
  if (val === '' || val === null || val === undefined) return
  const items = CATALOG_DATA[svc] || []
  const item = items[parseInt(val)]
  if (!item) return
  tr.querySelector('.iPrice').value = item.price
  if ((item.driver_rate || 0) > 0 && usesDriverPayment(svc)) {
    tr.dataset.catalogDriverRate = item.driver_rate
    const chk = tr.querySelector('.iNeedsDriver')
    const commWrap = tr.querySelector('.iCommWrap')
    if (chk) chk.checked = true
    if (commWrap) commWrap.style.display = 'flex'
    prefillDriverCommission(tr)
  } else {
    delete tr.dataset.catalogDriverRate
  }
  id.startsWith('er') ? ercalc(id) : rcalc(id)
}

function rentalDefaultDate (offsetDays) {
  const dt = new Date()
  dt.setDate(dt.getDate() + offsetDays)
  dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset())
  return dt.toISOString().slice(0, 16)
}

function addItemRow (svc, desc, qty, price) {
  rowCnt++
  const id = 'r' + rowCnt
  const card = document.createElement('div')
  card.className = 'hs-item-card'
  card.id = id
  card.innerHTML =
    `<div class="hs-ic-top">` +
    `<select class="iSvc" onchange="onSvcChange('${id}', true)">${buildSvcOpts(
      svc || ''
    )}</select>` +
    `<input type="text" class="iDesc" placeholder="Deskripsi" value="${(
      desc || ''
    ).replace(/"/g, '&quot;')}">` +
    `<button type="button" class="btn-del-row" onclick="delRow('${id}')">✕</button>` +
    `</div>` +
    `<div class="hs-rental-extra">` +
    `<div class="hs-rental-row1">` +
    `<div class="iMotorSourceWrap hs-ic-labeled" style="display:none"><span>Sumber Motor</span><select class="iMotorSource" onchange="onMotorSourceChange('${id}')"></select></div>` +
    `<div class="hs-ic-labeled hs-asset-wrap"><span>Armada</span><select class="iAsset" onchange="onRentalAssetChange('${id}')"><option value="">Pilih armada...</option></select></div>` +
    `<div class="hs-ic-labeled hs-paket-wrap" style="display:none"><span>Paket / Jenis</span><select class="iPaket" onchange="onPaketChange('${id}')"><option value="">── Harga dari Armada ──</option></select></div>` +
    `<div class="hs-ic-labeled"><span>Hari Sewa</span><input type="number" class="iDays" value="1" min="1" max="365" step="1" onchange="onDaysChange('${id}')"></div>` +
    `<div class="hs-ic-labeled"><span>Deposit (Rp)</span><input type="number" class="iDeposit" value="0" min="0"></div>` +
    `</div>` +
    `<div class="hs-dest-wrap"><span>Tujuan / Catatan</span><input type="text" class="iDest" placeholder="Tujuan / catatan mobil"></div>` +
    `</div>` +
    `<div class="hs-trip-extra" style="display:none">` +
    `<div class="hs-rental-row1">` +
    `<div class="hs-ic-labeled" style="min-width:180px"><span>Tipe Trip</span><select class="iTripType"><option value="">Pilih tipe...</option><option value="open_trip">Open Trip</option><option value="private_trip">Private Trip</option></select></div>` +
    `<div class="hs-ic-labeled" style="min-width:220px"><span>Nama Guide</span><select class="iGuide"><option value="">Pilih guide...</option></select></div>` +
    `</div>` +
    `</div>` +
    `<div class="hs-driver-extra">` +
    `<div class="iDriverCarRow" style="display:none">` +
    `<div class="hs-ic-labeled" style="flex:1"><span>Mobil Driver</span><select class="iDriverCar" onchange="onDriverCarChange('${id}')"><option value="">🚗 Pilih mobil/driver (opsional)...</option></select></div>` +
    `</div>` +
    `<label class="hs-driver-chk"><input type="checkbox" class="iNeedsDriver" onchange="onNeedsDriverChange('${id}')"> 🧾 Butuh tagihan driver</label>` +
    `<div class="iCommWrap" style="display:none">` +
    `<select class="iCommType"><option value="percent">Bagian Driver: %</option><option value="nominal">Potongan Hotel: Rp</option></select>` +
    `<input type="number" class="iCommValue" value="0" min="0" placeholder="Nilai">` +
    `</div>` +
    `</div>` +
    `<div class="hs-ic-nums">` +
    `<div class="hs-ic-labeled"><span class="iQtyLabel">QTY</span><input type="number" class="iQty" value="${
      qty || 1
    }" min="0.5" step="0.5" oninput="rcalc('${id}')"></div>` +
    `<div class="hs-ic-labeled"><span>Harga (Rp)</span><input type="number" class="iPrice" value="${
      price || 0
    }" min="0" oninput="rcalc('${id}')"></div>` +
    `<div class="hs-ic-subtotal"><span>Subtotal</span><strong class="iTotal">Rp 0</strong></div>` +
    `</div>`
  document.getElementById('itemsBody').appendChild(card)
  // Only auto-populate if service type was provided
  if (svc) {
    onSvcChange(id, true)
  } else {
    card.querySelector('.iGuide').innerHTML = buildGuideOpts('')
    rcalc(id)
  }
}

function onDaysChange (id) {
  const tr = document.getElementById(id)
  if (!tr) return
  const svc = tr.querySelector('.iSvc')?.value
  const daysInput = tr.querySelector('.iDays')
  const days = parseFloat(daysInput.value) || 1
  // For motor_rental: QTY = number of motors (don't overwrite). For others: QTY = days.
  if (svc !== 'motor_rental') {
    tr.querySelector('.iQty').value = Math.max(1, days)
  }
  rcalc(id)
  if (tr.querySelector('.iNeedsDriver')?.checked) prefillDriverCommission(tr)
}

function onSvcChange (id, isNew) {
  const tr = document.getElementById(id)
  if (!tr) return
  const svc = tr.querySelector('.iSvc').value
  const priceInput = tr.querySelector('.iPrice')
  const descInput = tr.querySelector('.iDesc')
  const rentalWrap = tr.querySelector('.hs-rental-extra')
  const tripWrap = tr.querySelector('.hs-trip-extra')
  const assetSelect = tr.querySelector('.iAsset')
  const assetWrap = tr.querySelector('.hs-asset-wrap')
  const destWrap = tr.querySelector('.hs-dest-wrap')
  const items = CATALOG_DATA[svc]
  const rentalItems =
    svc === 'motor_rental'
      ? RENTAL_MOTORS
      : svc === 'car_rental'
      ? RENTAL_CARS
      : []

  if (isRentalService(svc)) {
    // Show rental fields for motor_rental and car_rental
    rentalWrap.classList.add('open')
    assetSelect.innerHTML = buildRentalAssetOpts(rentalItems, assetSelect.value)
    if (assetWrap) assetWrap.style.display = svc === 'car_rental' ? 'none' : ''
    if (destWrap) destWrap.style.display = svc === 'car_rental' ? '' : 'none'
    // Show motor source dropdown for motor_rental
    const motorSourceWrap = tr.querySelector('.iMotorSourceWrap')
    const motorSourceSel = tr.querySelector('.iMotorSource')
    if (svc === 'motor_rental') {
      if (motorSourceWrap) motorSourceWrap.style.display = ''
      if (motorSourceSel)
        motorSourceSel.innerHTML = buildMotorSourceOpts(motorSourceSel.value)
      // QTY = jumlah motor unit; reset to 1 when switching to motor_rental
      const qtyLabel = tr.querySelector('.iQtyLabel')
      if (qtyLabel) qtyLabel.textContent = 'Jml Motor'
      if (isNew) tr.querySelector('.iQty').value = 1
    } else {
      if (motorSourceWrap) motorSourceWrap.style.display = 'none'
      if (motorSourceSel) motorSourceSel.value = ''
      const qtyLabel = tr.querySelector('.iQtyLabel')
      if (qtyLabel) qtyLabel.textContent = 'QTY'
    }
    // Set default 1 day
    if (!tr.querySelector('.iDays').value) tr.querySelector('.iDays').value = 1
    // Populate paket picker from catalog
    const paketWrap = tr.querySelector('.hs-paket-wrap')
    const paketSel = tr.querySelector('.iPaket')
    const catItems = CATALOG_DATA[svc] || []
    if (paketSel) paketSel.innerHTML = buildCatalogPaketOpts(svc, '')
    if (paketWrap) paketWrap.style.display = catItems.length ? '' : 'none'
  } else {
    // Hide rental fields for other services
    rentalWrap.classList.remove('open')
    assetSelect.innerHTML = '<option value="">Pilih armada...</option>'
    if (assetWrap) assetWrap.style.display = ''
    tr.querySelector('.iDays').value = 1
    tr.querySelector('.iDeposit').value = 0
    const _dest = tr.querySelector('.iDest')
    if (_dest) _dest.value = ''
  }

  if (isNarayanaTrip(svc)) {
    if (tripWrap) tripWrap.style.display = ''
    const guideSelect = tr.querySelector('.iGuide')
    if (guideSelect) guideSelect.innerHTML = buildGuideOpts(guideSelect.value)
  } else {
    if (tripWrap) tripWrap.style.display = 'none'
    const tripTypeSel = tr.querySelector('.iTripType')
    const guideSel = tr.querySelector('.iGuide')
    if (tripTypeSel) tripTypeSel.value = ''
    if (guideSel) guideSel.value = ''
  }

  if (items && items.length > 0) {
    if (isNew) {
      if (parseFloat(priceInput.value) === 0) priceInput.value = items[0].price
      if (!descInput.value.trim()) descInput.value = items[0].name
    }
  }

  if (isRentalService(svc)) {
    onRentalAssetChange(id, !isNew)
  }
  updateDriverExtra(tr)

  // Auto-enable driver split from catalog driver_rate (e.g. airport/harbor drop)
  if (
    isNew &&
    items &&
    items.length > 0 &&
    (items[0].driver_rate || 0) > 0 &&
    usesDriverPayment(svc)
  ) {
    tr.dataset.catalogDriverRate = items[0].driver_rate
    const chk = tr.querySelector('.iNeedsDriver')
    const commWrap = tr.querySelector('.iCommWrap')
    if (chk) chk.checked = true
    if (commWrap) commWrap.style.display = 'flex'
    prefillDriverCommission(tr)
  }
  rcalc(id)
}

function onRentalAssetChange (id, keepManualDesc) {
  const tr = document.getElementById(id)
  if (!tr) return
  const svc = tr.querySelector('.iSvc').value
  const assetSelect = tr.querySelector('.iAsset')
  const selectedId = assetSelect.value
  const source = svc === 'motor_rental' ? RENTAL_MOTORS : RENTAL_CARS
  const chosen = source.find(item => String(item.id) === String(selectedId))
  if (!chosen) {
    rcalc(id)
    return
  }
  const priceInput = tr.querySelector('.iPrice')
  const descInput = tr.querySelector('.iDesc')
  // Only fill price from car if no catalog paket is selected
  const paketSelected = !!tr.querySelector('.iPaket')?.value
  if (
    !paketSelected &&
    (!priceInput.value || parseFloat(priceInput.value) === 0 || !keepManualDesc)
  ) {
    priceInput.value = chosen.daily_rate
  }
  if (
    !descInput.value.trim() ||
    descInput.dataset.autoFilled === '1' ||
    !keepManualDesc
  ) {
    descInput.value = chosen.label
    descInput.dataset.autoFilled = '1'
  }
  if (tr.querySelector('.iNeedsDriver')?.checked) prefillDriverCommission(tr)
  // Auto-check driver if this vehicle has a preset driver rate
  if (chosen.driver_daily_rate > 0) {
    const chk = tr.querySelector('.iNeedsDriver')
    const commWrap = tr.querySelector('.iCommWrap')
    if (chk) chk.checked = true
    if (commWrap) commWrap.style.display = 'flex'
    prefillDriverCommission(tr)
  }
  rcalc(id)
}

function onMotorSourceChange (id) {
  const tr = document.getElementById(id)
  if (!tr) return
  const motorSourceSel = tr.querySelector('.iMotorSource')
  const motorSource = motorSourceSel.value
  const assetSelect = tr.querySelector('.iAsset')
  assetSelect.innerHTML = buildRentalAssetOpts(
    RENTAL_MOTORS,
    assetSelect.value,
    motorSource
  )
  onRentalAssetChange(id, false)
}

function syncRentalDuration (id) {
  const tr = document.getElementById(id)
  if (!tr) return
  const svc = tr.querySelector('.iSvc').value
  if (!isRentalService(svc)) return
  const startVal = tr.querySelector('.iStart').value
  const endVal = tr.querySelector('.iEnd').value
  if (!startVal || !endVal) return
  const start = new Date(startVal)
  const end = new Date(endVal)
  const diffHours = (end - start) / (1000 * 60 * 60)
  if (Number.isFinite(diffHours) && diffHours > 0) {
    tr.querySelector('.iQty').value = Math.max(1, Math.ceil(diffHours / 24))
    rcalc(id)
  }
}

function delRow (id) {
  const el = document.getElementById(id)
  if (el) el.remove()
  refreshTotal()
}

function rcalc (id) {
  const tr = document.getElementById(id)
  if (!tr) return
  const svc = tr.querySelector('.iSvc')?.value
  const qty = parseFloat(tr.querySelector('.iQty').value) || 0
  const price = parseFloat(tr.querySelector('.iPrice').value) || 0
  // For motor_rental: subtotal = motors × days × daily_rate
  const days =
    svc === 'motor_rental'
      ? parseFloat(tr.querySelector('.iDays')?.value) || 1
      : 1
  const t = qty * days * price
  tr.querySelector('.iTotal').textContent =
    'Rp ' + Math.round(t).toLocaleString('id-ID')
  refreshTotal()
}

function subtotal () {
  let t = 0
  document.querySelectorAll('#itemsBody .hs-item-card').forEach(tr => {
    const svc = tr.querySelector('.iSvc')?.value
    const qty = parseFloat(tr.querySelector('.iQty')?.value) || 0
    const price = parseFloat(tr.querySelector('.iPrice')?.value) || 0
    const days =
      svc === 'motor_rental'
        ? parseFloat(tr.querySelector('.iDays')?.value) || 1
        : 1
    t += qty * days * price
  })
  return t
}

function getTaxRate () {
  const sel = document.getElementById('fTaxRate')
  if (!sel) return 0
  if (sel.value === 'custom')
    return parseFloat(document.getElementById('fTaxCustom')?.value) || 0
  return parseFloat(sel.value) || 0
}

function grandTotal () {
  const sub = subtotal()
  const scRate =
    parseFloat(document.getElementById('fServiceCharge')?.value) || 0
  const discRate = parseFloat(document.getElementById('fDiscount')?.value) || 0
  const sc = sub * (scRate / 100)
  const disc = sub * (discRate / 100)
  const afterCD = sub + sc - disc
  const rate = getTaxRate()
  return afterCD + afterCD * (rate / 100)
}

function onTaxRateChange () {
  const sel = document.getElementById('fTaxRate')
  document.getElementById('customTaxWrap').style.display =
    sel.value === 'custom' ? '' : 'none'
  refreshTotal()
}

function refreshTotal () {
  const sub = subtotal()
  const rate = getTaxRate()
  const scRate =
    parseFloat(document.getElementById('fServiceCharge')?.value) || 0
  const discRate = parseFloat(document.getElementById('fDiscount')?.value) || 0
  const sc = sub * (scRate / 100)
  const disc = sub * (discRate / 100)
  const afterCD = sub + sc - disc
  const tax = afterCD * (rate / 100)
  const tot = afterCD + tax
  const dp = parseFloat(document.getElementById('fPaid')?.value) || 0
  const sisa = Math.max(0, tot - dp)
  const fmt = v => 'Rp ' + Math.round(v).toLocaleString('id-ID')

  document.getElementById('tpSubtotal').textContent = fmt(sub)
  document.getElementById('tpGrand').textContent = fmt(tot)

  const scRow = document.getElementById('tpScRow')
  scRow.style.display = scRate > 0 ? '' : 'none'
  document.getElementById('tpSc').textContent = `${fmt(sc)} (${scRate}%)`

  const discRow = document.getElementById('tpDiscRow')
  discRow.style.display = discRate > 0 ? '' : 'none'
  document.getElementById('tpDisc').textContent = `- ${fmt(
    disc
  )} (${discRate}%)`

  const taxRow = document.getElementById('tpTaxRow')
  taxRow.style.display = rate > 0 ? '' : 'none'
  document.getElementById('tpTax').textContent = `${fmt(tax)} (${rate}%)`

  const dpEl = document.getElementById('fPaid')
  const hasDp = dpEl && parseFloat(dpEl.value) > 0
  document.getElementById('tpDpRow').style.display = hasDp ? '' : 'none'
  document.getElementById('tpSisaRow').style.display =
    hasDp && sisa > 0 ? '' : 'none'
  document.getElementById('tpDp').textContent = fmt(dp)
  document.getElementById('tpSisa').textContent = fmt(sisa)

  enforceMaxPaid()
  if (document.getElementById('fFullPay').checked)
    document.getElementById('fPaid').value = Math.round(tot)
}

function enforceMaxPaid () {
  const mx = grandTotal(),
    inp = document.getElementById('fPaid')
  if (parseFloat(inp.value) > mx) inp.value = Math.round(mx)
}

function toggleFullPay (checked) {
  document.getElementById('fPaid').value = checked
    ? Math.round(grandTotal())
    : 0
  refreshTotal()
}

// ── Open/Close ────────────────────────────────────────────────────────────────
function openCreateModal () {
  document.getElementById('createModal').classList.add('open')
  ;['fGuestName', 'fPhone', 'fRoom', 'fNotes'].forEach(id => {
    const e = document.getElementById(id)
    if (e) e.value = ''
  })
  document.getElementById('fGuestSelect').value = ''
  document.getElementById('fBookingId').value = ''
  document.getElementById('fPaid').value = 0
  document.getElementById('fFullPay').checked = false
  document.getElementById('fTaxRate').value = '0'
  document.getElementById('customTaxWrap').style.display = 'none'
  if (document.getElementById('fTaxCustom'))
    document.getElementById('fTaxCustom').value = 0
  document.getElementById('fServiceCharge').value = 0
  document.getElementById('fDiscount').value = 0
  document.getElementById('itemsBody').innerHTML = ''
  rowCnt = 0
  setGuestMode('inhouse')
  addItemRow()
  refreshTotal()
}

function closeCreateModal () {
  document.getElementById('createModal').classList.remove('open')
}

// ── Submit create ─────────────────────────────────────────────────────────────
function submitCreate () {
  const guestName = getGuestName()
  if (!guestName) {
    alert('Please select or enter a guest name')
    return
  }

  const rows = document.querySelectorAll('#itemsBody .hs-item-card')
  if (!rows.length) {
    alert('Add at least one service item')
    return
  }

  const items = []
  for (const tr of rows) {
    const svc = tr.querySelector('.iSvc').value
    if (!svc) {
      alert('Select service type for all rows')
      return
    }
    const motorId =
      svc === 'motor_rental'
        ? parseInt(tr.querySelector('.iAsset').value || '0', 10)
        : 0
    const carId =
      svc === 'car_rental'
        ? parseInt(tr.querySelector('.iAsset').value || '0', 10)
        : 0
    const driverCarId = getDriverCarId(tr, svc)
    const commType = tr.querySelector('.iCommType')?.value || 'percent'
    const commValue = parseFloat(tr.querySelector('.iCommValue')?.value) || 0
    // Allow driver split without specific car when catalog commission is set
    const needsDriver =
      usesDriverPayment(svc) &&
      !!tr.querySelector('.iNeedsDriver')?.checked &&
      (driverCarId > 0 || commValue > 0)
    const daysInput = tr.querySelector('.iDays')
    const days = daysInput ? parseFloat(daysInput.value) || 1 : 1
    const tripType = tr.querySelector('.iTripType')?.value || ''
    const guideId = parseInt(tr.querySelector('.iGuide')?.value || '0', 10)

    // Generate start and end dates from days
    const today = new Date()
    const startDt = today.toISOString().slice(0, 19).replace('T', ' ')
    const endDate = new Date(today)
    endDate.setDate(endDate.getDate() + days)
    const endDt = endDate.toISOString().slice(0, 19).replace('T', ' ')

    if (svc === 'motor_rental' && !motorId) {
      alert('Item rental motor wajib pilih armada')
      return
    }
    if (svc === 'narayana_trip') {
      if (!tripType) {
        alert('Narayana Trip wajib pilih tipe trip (Open/Private)')
        return
      }
      if (!guideId) {
        alert('Narayana Trip wajib pilih nama guide')
        return
      }
    }
    const _motorUnits = parseFloat(tr.querySelector('.iQty').value) || 1
    const _motorDays = parseFloat(tr.querySelector('.iDays')?.value) || 1
    items.push({
      service_type: svc,
      description: tr.querySelector('.iDesc').value.trim(),
      // For motor_rental: send motors×days as qty so server total_price = motors×days×rate
      qty: svc === 'motor_rental' ? _motorUnits * _motorDays : _motorUnits,
      motor_count: svc === 'motor_rental' ? _motorUnits : 1,
      unit_price: parseFloat(tr.querySelector('.iPrice').value) || 0,
      motor_id: motorId || null,
      car_id: (svc === 'car_rental' ? carId : driverCarId) || null,
      needs_driver_payment: needsDriver ? 1 : 0,
      commission_type: commType,
      commission_value: commValue,
      trip_type: svc === 'narayana_trip' ? tripType : null,
      guide_id: svc === 'narayana_trip' ? guideId : null,
      guide_name:
        svc === 'narayana_trip'
          ? tr.querySelector('.iGuide')?.selectedOptions?.[0]?.textContent || ''
          : null,
      start_dt: svc === 'motor_rental' || svc === 'car_rental' ? startDt : null,
      end_dt: svc === 'motor_rental' || svc === 'car_rental' ? endDt : null,
      deposit: parseFloat(tr.querySelector('.iDeposit').value) || 0,
      trip_destination: tr.querySelector('.iDest').value.trim() || null
    })
  }

  const btn = document.getElementById('createBtn')
  btn.disabled = true
  btn.textContent = 'Creating...'

  const fd = new FormData()
  fd.append('action', 'create')
  fd.append('guest_name', guestName)
  fd.append('guest_phone', document.getElementById('fPhone').value.trim())
  fd.append('room_number', document.getElementById('fRoom').value.trim())
  fd.append('booking_id', document.getElementById('fBookingId').value || '')
  fd.append('items', JSON.stringify(items))
  fd.append('payment_method', document.getElementById('fPayMethod').value)
  fd.append('paid_amount', document.getElementById('fPaid').value || 0)
  fd.append('tax_rate', getTaxRate())
  fd.append(
    'service_charge_rate',
    document.getElementById('fServiceCharge').value || 0
  )
  fd.append('discount_rate', document.getElementById('fDiscount').value || 0)
  fd.append('notes', document.getElementById('fNotes').value.trim())

  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        closeCreateModal()
        const cbMsg = res.cashbook ? '\n✅ Tercatat di Buku Kas' : ''
        alert('Invoice ' + res.invoice_number + ' created!' + cbMsg)
        location.reload()
      } else {
        alert('Error: ' + (res.message || 'Unknown'))
        btn.disabled = false
        btn.textContent = '✅ Create Invoice'
      }
    })
    .catch(() => {
      alert('Network error')
      btn.disabled = false
      btn.textContent = '✅ Create Invoice'
    })
}

// ── Status ────────────────────────────────────────────────────────────────────
function updateStatus (id, status) {
  const fd = new FormData()
  fd.append('action', 'update_status')
  fd.append('id', id)
  fd.append('status', status)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (!res.success) alert('Failed to update status')
    })
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteInvoice (id, code) {
  if (!confirm('Delete invoice ' + code + '? Cannot be undone.')) return
  const fd = new FormData()
  fd.append('action', 'delete')
  fd.append('id', id)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) location.reload()
      else alert('Delete failed')
    })
}

// ── Pay modal ─────────────────────────────────────────────────────────────────
function openPayModal (id, remaining, invNo) {
  document.getElementById('pInvId').value = id
  document.getElementById('pInvNo').textContent = 'Invoice: ' + invNo
  document.getElementById('pRemaining').textContent =
    'Rp ' + Math.round(remaining).toLocaleString('id-ID')
  document.getElementById('pAmount').value = Math.round(remaining)
  document.getElementById('pAmount2').value = 0
  document.getElementById('pSplitToggle').checked = false
  document.getElementById('pSplitRow').style.display = 'none'
  document.getElementById('payModal').classList.add('open')
}

function closePayModal () {
  document.getElementById('payModal').classList.remove('open')
}

function toggleSplitPay () {
  const on = document.getElementById('pSplitToggle').checked
  document.getElementById('pSplitRow').style.display = on ? 'flex' : 'none'
}

// Sends one add_payment request; resolves with the parsed JSON response.
function sendAddPayment (id, amount, method) {
  const fd = new FormData()
  fd.append('action', 'add_payment')
  fd.append('id', id)
  fd.append('amount', amount)
  fd.append('method', method)
  return fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  }).then(r => r.json())
}

function submitPay () {
  const id = document.getElementById('pInvId').value
  const amount = parseFloat(document.getElementById('pAmount').value) || 0
  const method = document.getElementById('pMethod').value
  const isSplit = document.getElementById('pSplitToggle').checked
  const amount2 = isSplit
    ? parseFloat(document.getElementById('pAmount2').value) || 0
    : 0
  const method2 = document.getElementById('pMethod2').value

  if (amount <= 0) {
    alert('Enter valid amount')
    return
  }
  if (isSplit && amount2 <= 0) {
    alert('Isi nominal untuk metode ke-2, atau matikan opsi split.')
    return
  }

  const btn = document.getElementById('payBtn')
  btn.disabled = true
  btn.textContent = 'Saving...'

  sendAddPayment(id, amount, method)
    .then(res => {
      if (!res.success)
        throw new Error(res.message || 'Unknown error (payment 1)')
      if (!isSplit) return res
      // Second leg of the split payment (e.g. sisanya via kartu/transfer)
      return sendAddPayment(id, amount2, method2).then(res2 => {
        if (!res2.success)
          throw new Error(res2.message || 'Unknown error (payment 2)')
        return res2
      })
    })
    .then(res => {
      closePayModal()
      let msg =
        'Payment saved! ' +
        (res.cashbook ? '✅ Tercatat di Buku Kas' : '⚠️ Gagal sync ke Buku Kas')
      if (res.motors_auto_returned && res.motors_auto_returned.length > 0) {
        msg +=
          '\n🏍️ ' +
          res.motors_auto_returned.length +
          ' motor otomatis ditandai sudah kembali (invoice lunas)'
      }
      if (res.cars_auto_returned && res.cars_auto_returned.length > 0) {
        msg +=
          '\n🚗 ' +
          res.cars_auto_returned.length +
          ' mobil otomatis ditandai sudah kembali (invoice lunas, tagihan driver otomatis update)'
      }
      alert(msg)
      location.reload()
    })
    .catch(err => {
      alert('Error: ' + err.message)
      btn.disabled = false
      btn.textContent = '💾 Save & Sync to Cashbook'
    })
}

// ── SETTINGS ─────────────────────────────────────────────────────────────────
function openSettingsModal () {
  document.getElementById('settingsModal').classList.add('open')
  switchTab('inv')
}

function closeSettingsModal () {
  document.getElementById('settingsModal').classList.remove('open')
}

function switchTab (t) {
  ;['inv', 'catalog', 'svctype', 'guide'].forEach(id => {
    document.getElementById('tab-' + id).classList.toggle('active', id === t)
    document.getElementById('pane-' + id).classList.toggle('active', id === t)
  })
}

function previewLogo (inp) {
  const prev = document.getElementById('logoPreview')
  if (inp.files && inp.files[0]) {
    const reader = new FileReader()
    reader.onload = e => {
      prev.src = e.target.result
      prev.style.display = 'block'
    }
    reader.readAsDataURL(inp.files[0])
  }
}

function saveSettings () {
  const btn = document.getElementById('btnSaveSettings')
  btn.disabled = true
  btn.textContent = 'Saving...'
  const fd = new FormData()
  fd.append('action', 'save_hs_settings')
  fd.append('company_name', document.getElementById('sCmpName').value.trim())
  fd.append('company_website', document.getElementById('sCmpWeb').value.trim())
  fd.append('company_phone', document.getElementById('sCmpPhone').value.trim())
  fd.append('company_email', document.getElementById('sCmpEmail').value.trim())
  fd.append('company_address', document.getElementById('sCmpAddr').value.trim())
  fd.append(
    'payment_info_bank',
    document.getElementById('sPayBank').value.trim()
  )
  fd.append(
    'payment_info_account',
    document.getElementById('sPayAccount').value.trim()
  )
  fd.append(
    'payment_info_name',
    document.getElementById('sPayName').value.trim()
  )
  fd.append(
    'payment_info_note',
    document.getElementById('sPayNote').value.trim()
  )
  const logoFile = document.getElementById('sLogoFile').files[0]
  if (logoFile) fd.append('logo_file', logoFile)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        alert('✅ Settings saved!')
        closeSettingsModal()
        location.reload()
      } else {
        alert('Error: ' + (res.message || 'unknown'))
      }
      btn.disabled = false
      btn.textContent = '💾 Save Settings'
    })
    .catch(() => {
      alert('Network error')
      btn.disabled = false
      btn.textContent = '💾 Save Settings'
    })
}

// ── CATALOG ───────────────────────────────────────────────────────────────────
let catRowCnt = 0
const SVC_OPTIONS = window.SVC_OPTIONS || []

function buildSvcOptsFor (selected = '') {
  return SVC_OPTIONS.map(
    o =>
      `<option value="${o.val}" ${o.val === selected ? 'selected' : ''}>${
        o.lbl
      }</option>`
  ).join('')
}

function addCatalogRow () {
  catRowCnt++
  const id = 'new_' + catRowCnt
  const tr = document.createElement('tr')
  tr.id = 'ctr' + id
  tr.innerHTML =
    `<td><select class="cSType">${buildSvcOptsFor()}</select></td>` +
    `<td><input type="text" class="cName" placeholder="ex: Honda Beat 1 Hari"></td>` +
    `<td><input type="number" class="cPrice" value="0" min="0"></td>` +
    `<td><input type="number" class="cDriverRate" value="0" min="0" placeholder="0"></td>` +
    `<td><input type="text" class="cUnit" value="unit"></td>` +
    `<td><input type="number" class="cSort" value="0" style="width:45px"></td>` +
    `<td style="display:flex;gap:3px">` +
    `<button class="btn-cat-save" onclick="saveCatalogRow('${id}')">💾</button>` +
    `<button class="btn-cat-del" onclick="document.getElementById('ctr${id}').remove()">✕</button>` +
    `</td>`
  document.getElementById('catalogBody').prepend(tr)
}

function saveCatalogRow (cid) {
  const tr = document.getElementById('ctr' + cid)
  if (!tr) return
  const fd = new FormData()
  fd.append('action', 'save_catalog_item')
  fd.append('cid', isNaN(cid) ? 0 : cid)
  fd.append('service_type', tr.querySelector('.cSType').value)
  fd.append('item_name', tr.querySelector('.cName').value.trim())
  fd.append('default_price', tr.querySelector('.cPrice').value)
  fd.append('driver_rate', tr.querySelector('.cDriverRate')?.value || 0)
  fd.append('unit', tr.querySelector('.cUnit').value.trim() || 'unit')
  fd.append('sort_order', tr.querySelector('.cSort').value)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        tr.id = 'ctr' + res.id
        tr.querySelectorAll('button')[0].setAttribute(
          'onclick',
          'saveCatalogRow(' + res.id + ')'
        )
        tr.querySelectorAll('button')[1].setAttribute(
          'onclick',
          'deleteCatalogRow(' + res.id + ')'
        )
        tr.style.background = '#f0fdf4'
        setTimeout(() => (tr.style.background = ''), 1500)
      } else {
        alert('Error: ' + (res.message || 'failed'))
      }
    })
}

function deleteCatalogRow (cid) {
  if (!confirm('Hapus item ini dari katalog?')) return
  const fd = new FormData()
  fd.append('action', 'delete_catalog_item')
  fd.append('cid', cid)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        const el = document.getElementById('ctr' + cid)
        if (el) el.remove()
      } else alert('Error')
    })
}

const CATALOG = window.CATALOG_LIST || []

// ── EDIT INVOICE ──────────────────────────────────────────────────────────────
let eRowCnt = 0

const ACTIVE_BIZ_ID = window.ACTIVE_BIZ_ID || 0

function openEditModal (id) {
  fetch(
    'hotel-services.php?get_invoice=1&id=' +
      id +
      '&business_id=' +
      encodeURIComponent(ACTIVE_BIZ_ID),
    {
      credentials: 'include'
    }
  )
    .then(r => r.json())
    .then(inv => {
      if (!inv.success) {
        alert(inv.message || 'Cannot load invoice')
        return
      }
      document.getElementById('eInvId').value = inv.id
      document.getElementById('eInvNo').textContent =
        'Invoice: ' + inv.invoice_number
      document.getElementById('eGuestName').value = inv.guest_name || ''
      document.getElementById('ePhone').value = inv.guest_phone || ''
      document.getElementById('eRoom').value = inv.room_number || ''
      document.getElementById('ePayMethod').value = inv.payment_method || 'cash'
      document.getElementById('ePaid').value = inv.paid_amount || 0
      document.getElementById('eNotes').value = inv.notes || ''
      const tr2 = parseFloat(inv.tax_rate) || 0
      const taxSel = document.getElementById('eTaxRate')
      if (['0', '5', '10', '11'].includes(String(tr2))) {
        taxSel.value = String(tr2)
        document.getElementById('eCustomTaxWrap').style.display = 'none'
      } else {
        taxSel.value = 'custom'
        document.getElementById('eCustomTaxWrap').style.display = ''
        document.getElementById('eTaxCustom').value = tr2
      }
      document.getElementById('eServiceCharge').value =
        parseFloat(inv.service_charge_rate) || 0
      document.getElementById('eDiscount').value =
        parseFloat(inv.discount_rate) || 0
      document.getElementById('eItemsBody').innerHTML = ''
      eRowCnt = 0
      ;(inv.items || []).forEach(it => eAddItemRow(it))
      eRefreshTotal()
      document.getElementById('editModal').classList.add('open')
    })
    .catch(() => alert('Network error loading invoice'))
}

function closeEditModal () {
  document.getElementById('editModal').classList.remove('open')
}

function eAddItemRow (itemOrSvc, desc, qty, price) {
  const item =
    typeof itemOrSvc === 'object' && itemOrSvc !== null
      ? itemOrSvc
      : {
          service_type: itemOrSvc,
          description: desc,
          quantity: qty,
          unit_price: price,
          motor_id: null,
          car_id: null,
          rental_days: 1,
          deposit: 0,
          trip_destination: null
        }
  eRowCnt++
  const id2 = 'er' + eRowCnt
  const card = document.createElement('div')
  card.className = 'hs-item-card'
  card.id = id2
  card.innerHTML =
    `<div class="hs-ic-top">` +
    `<select class="iSvc" onchange="eOnSvcChange('${id2}', true)">${buildSvcOpts(
      item.service_type || ''
    )}</select>` +
    `<input type="text" class="iDesc" placeholder="Deskripsi" value="${(
      item.description || ''
    ).replace(/"/g, '&quot;')}">` +
    `<button type="button" class="btn-del-row" onclick="eDelRow('${id2}')">\u2715</button>` +
    `</div>` +
    `<div class="hs-rental-extra">` +
    `<div class="hs-rental-row1">` +
    `<div class="iMotorSourceWrap hs-ic-labeled" style="display:none"><span>Sumber Motor</span><select class="iMotorSource" onchange="onMotorSourceChange('${id2}')"></select></div>` +
    `<div class="hs-ic-labeled hs-asset-wrap"><span>Armada</span><select class="iAsset" onchange="eOnRentalAssetChange('${id2}')"></select></div>` +
    `<div class="hs-ic-labeled hs-paket-wrap" style="display:none"><span>Paket / Jenis</span><select class="iPaket" onchange="onPaketChange('${id2}')"><option value="">── Harga dari Armada ──</option></select></div>` +
    `<div class="hs-ic-labeled"><span>Hari Sewa</span><input type="number" class="iDays" value="${
      item.rental_days || 1
    }" min="1" max="365" step="1" onchange="eOnDaysChange('${id2}')"></div>` +
    `<div class="hs-ic-labeled"><span>Deposit (Rp)</span><input type="number" class="iDeposit" value="${
      item.deposit || 0
    }" min="0"></div>` +
    `</div>` +
    `<div class="hs-dest-wrap"><span>Tujuan / Catatan</span><input type="text" class="iDest" value="${(
      item.trip_destination || ''
    ).replace(/"/g, '&quot;')}" placeholder="Tujuan / catatan mobil"></div>` +
    `</div>` +
    `<div class="hs-trip-extra" style="display:none">` +
    `<div class="hs-rental-row1">` +
    `<div class="hs-ic-labeled" style="min-width:180px"><span>Tipe Trip</span><select class="iTripType"><option value="">Pilih tipe...</option><option value="open_trip" ${
      item.trip_type === 'open_trip' ? 'selected' : ''
    }>Open Trip</option><option value="private_trip" ${
      item.trip_type === 'private_trip' ? 'selected' : ''
    }>Private Trip</option></select></div>` +
    `<div class="hs-ic-labeled" style="min-width:220px"><span>Nama Guide</span><select class="iGuide"></select></div>` +
    `</div>` +
    `</div>` +
    `<div class="hs-driver-extra">` +
    `<div class="iDriverCarRow" style="display:none">` +
    `<div class="hs-ic-labeled" style="flex:1"><span>Mobil Driver</span><select class="iDriverCar" onchange="onDriverCarChange('${id2}')"><option value="">\ud83d\ude97 Pilih mobil/driver (opsional)...</option></select></div>` +
    `</div>` +
    `<label class="hs-driver-chk"><input type="checkbox" class="iNeedsDriver" ${
      item.needs_driver_payment ? 'checked' : ''
    } onchange="onNeedsDriverChange('${id2}')"> \ud83e\uddfe Butuh tagihan driver</label>` +
    `<div class="iCommWrap" style="display:${
      item.needs_driver_payment ? 'flex' : 'none'
    }">` +
    `<select class="iCommType"><option value="percent" ${
      (item.commission_type || 'percent') === 'percent' ? 'selected' : ''
    }>Bagian Driver: %</option><option value="nominal" ${
      item.commission_type === 'nominal' ? 'selected' : ''
    }>Potongan Hotel: Rp</option></select>` +
    `<input type="number" class="iCommValue" value="${
      item.commission_value || 0
    }" min="0" placeholder="Nilai">` +
    `</div>` +
    `</div>` +
    `<div class="hs-ic-nums">` +
    `<div class="hs-ic-labeled"><span class="iQtyLabel">QTY</span><input type="number" class="iQty" value="${
      item.quantity || 1
    }" min="0.5" step="0.5" oninput="ercalc('${id2}')"></div>` +
    `<div class="hs-ic-labeled"><span>Harga (Rp)</span><input type="number" class="iPrice" value="${
      item.unit_price || 0
    }" min="0" oninput="ercalc('${id2}')"></div>` +
    `<div class="hs-ic-subtotal"><span>Subtotal</span><strong class="iTotal">Rp 0</strong></div>` +
    `</div>`
  document.getElementById('eItemsBody').appendChild(card)
  if (item.service_type === 'motor_rental') {
    let motorOpts = [...RENTAL_MOTORS]
    if (item.motor_id && !motorOpts.find(m => m.id === item.motor_id)) {
      motorOpts = [
        {
          id: item.motor_id,
          label:
            (item.motor_name || 'Motor') +
            (item.plate_number ? ' (' + item.plate_number + ')' : ''),
          daily_rate: item.daily_rate || 0
        },
        ...motorOpts
      ]
    }
    card.querySelector('.iAsset').innerHTML = buildRentalAssetOpts(
      motorOpts,
      item.motor_id
    )
  }
  if (item.service_type === 'car_rental') {
    let carOpts = [...RENTAL_CARS]
    if (item.car_id && !carOpts.find(c => c.id === item.car_id)) {
      const label =
        (item.car_name || 'Mobil') +
        (item.plate_number ? ' (' + item.plate_number + ')' : '') +
        (item.car_type ? ' - ' + item.car_type : '')
      carOpts = [
        {
          id: item.car_id,
          label: label,
          daily_rate: item.daily_rate || 0
        },
        ...carOpts
      ]
    }
    card.querySelector('.iAsset').innerHTML = buildRentalAssetOpts(
      carOpts,
      item.car_id
    )
  }
  if (
    (item.service_type === 'airport_drop' ||
      item.service_type === 'harbor_drop') &&
    item.car_id
  ) {
    let carOpts = [...RENTAL_CARS]
    if (!carOpts.find(c => c.id === item.car_id)) {
      const label =
        (item.car_name || 'Mobil') +
        (item.plate_number ? ' (' + item.plate_number + ')' : '')
      carOpts = [
        {
          id: item.car_id,
          label: label,
          daily_rate: item.daily_rate || 0
        },
        ...carOpts
      ]
    }
    const driverCarSelect = card.querySelector('.iDriverCar')
    if (driverCarSelect)
      driverCarSelect.innerHTML = buildRentalAssetOpts(carOpts, item.car_id)
  }
  const eGuide = card.querySelector('.iGuide')
  if (eGuide) {
    eGuide.innerHTML = buildGuideOpts(item.guide_id || '')
  }
  // Only trigger onSvcChange for existing rows (loaded from API), not for new empty rows
  // Pass false to indicate we're loading from API, not creating new
  if (item.service_type) {
    eOnSvcChange(id2, false)
  }
  updateDriverExtra(card)
  ercalc(id2)
}

function eOnSvcChange (id2, isNew) {
  const tr3 = document.getElementById(id2)
  if (!tr3) return
  const svc = tr3.querySelector('.iSvc').value
  const priceInput = tr3.querySelector('.iPrice')
  const descInput = tr3.querySelector('.iDesc')
  const rentalWrap = tr3.querySelector('.hs-rental-extra')
  const tripWrap = tr3.querySelector('.hs-trip-extra')
  const assetSelect = tr3.querySelector('.iAsset')
  const assetWrap = tr3.querySelector('.hs-asset-wrap')
  const destWrap3 = tr3.querySelector('.hs-dest-wrap')
  const items = CATALOG_DATA[svc]

  // Only rebuild asset dropdown if THIS IS A NEW ROW (not loading from API)
  if (isNew) {
    const rentalItems =
      svc === 'motor_rental'
        ? RENTAL_MOTORS
        : svc === 'car_rental'
        ? RENTAL_CARS
        : []
    if (isRentalService(svc)) {
      rentalWrap.classList.add('open')
      assetSelect.innerHTML = buildRentalAssetOpts(
        rentalItems,
        assetSelect.value
      )
      if (assetWrap)
        assetWrap.style.display = svc === 'car_rental' ? 'none' : ''
      if (destWrap3)
        destWrap3.style.display = svc === 'car_rental' ? '' : 'none'
      // Show motor source dropdown for motor_rental
      const motorSourceWrap = tr3.querySelector('.iMotorSourceWrap')
      const motorSourceSel = tr3.querySelector('.iMotorSource')
      if (svc === 'motor_rental') {
        if (motorSourceWrap) motorSourceWrap.style.display = ''
        if (motorSourceSel)
          motorSourceSel.innerHTML = buildMotorSourceOpts(motorSourceSel.value)
        const ql = tr3.querySelector('.iQtyLabel')
        if (ql) ql.textContent = 'Jml Motor'
        if (isNew) tr3.querySelector('.iQty').value = 1
      } else {
        if (motorSourceWrap) motorSourceWrap.style.display = 'none'
        if (motorSourceSel) motorSourceSel.value = ''
        const ql = tr3.querySelector('.iQtyLabel')
        if (ql) ql.textContent = 'QTY'
      }
      if (!tr3.querySelector('.iDays').value)
        tr3.querySelector('.iDays').value = 1
      // Populate paket picker from catalog
      const paketWrap3 = tr3.querySelector('.hs-paket-wrap')
      const paketSel3 = tr3.querySelector('.iPaket')
      const catItems3 = CATALOG_DATA[svc] || []
      if (paketSel3) paketSel3.innerHTML = buildCatalogPaketOpts(svc, '')
      if (paketWrap3) paketWrap3.style.display = catItems3.length ? '' : 'none'
    } else {
      rentalWrap.classList.remove('open')
      assetSelect.innerHTML = '<option value="">Pilih armada...</option>'
      if (assetWrap) assetWrap.style.display = ''
      tr3.querySelector('.iDays').value = 1
      tr3.querySelector('.iDeposit').value = 0
      const _d = tr3.querySelector('.iDest')
      if (_d) _d.value = ''
    }
  } else {
    // Loading from API - just show/hide rental fields, don't rebuild dropdown
    if (isRentalService(svc)) {
      rentalWrap.classList.add('open')
      if (assetWrap)
        assetWrap.style.display = svc === 'car_rental' ? 'none' : ''
      if (destWrap3)
        destWrap3.style.display = svc === 'car_rental' ? '' : 'none'
    } else {
      rentalWrap.classList.remove('open')
      assetSelect.innerHTML = '<option value="">Pilih armada...</option>'
      if (assetWrap) assetWrap.style.display = ''
      tr3.querySelector('.iDays').value = 1
      tr3.querySelector('.iDeposit').value = 0
      const _d = tr3.querySelector('.iDest')
      if (_d) _d.value = ''
    }
  }

  if (isNarayanaTrip(svc)) {
    if (tripWrap) tripWrap.style.display = ''
    const guideSelect = tr3.querySelector('.iGuide')
    if (guideSelect) guideSelect.innerHTML = buildGuideOpts(guideSelect.value)
  } else {
    if (tripWrap) tripWrap.style.display = 'none'
    const tripTypeSel = tr3.querySelector('.iTripType')
    const guideSel = tr3.querySelector('.iGuide')
    if (tripTypeSel) tripTypeSel.value = ''
    if (guideSel) guideSel.value = ''
  }

  if (items && items.length > 0) {
    if (isNew) {
      if (parseFloat(priceInput.value) === 0) priceInput.value = items[0].price
      if (!descInput.value.trim()) descInput.value = items[0].name
    }
    // else: loading from API — don't overwrite existing price/desc
  }

  if (isRentalService(svc)) {
    eOnRentalAssetChange(id2, !isNew)
  }
  updateDriverExtra(tr3)

  // Auto-enable driver split from catalog driver_rate (e.g. airport/harbor drop)
  if (
    isNew &&
    items &&
    items.length > 0 &&
    (items[0].driver_rate || 0) > 0 &&
    usesDriverPayment(svc)
  ) {
    tr3.dataset.catalogDriverRate = items[0].driver_rate
    const chk = tr3.querySelector('.iNeedsDriver')
    const commWrap = tr3.querySelector('.iCommWrap')
    if (chk) chk.checked = true
    if (commWrap) commWrap.style.display = 'flex'
    prefillDriverCommission(tr3)
  }
  ercalc(id2)
}

function eOnRentalAssetChange (id2, keepManualDesc) {
  const tr3 = document.getElementById(id2)
  if (!tr3) return
  const svc = tr3.querySelector('.iSvc').value
  const assetSelect = tr3.querySelector('.iAsset')
  const selectedId = assetSelect.value
  const source = svc === 'motor_rental' ? RENTAL_MOTORS : RENTAL_CARS
  const chosen = source.find(item => String(item.id) === String(selectedId))
  if (!chosen) {
    ercalc(id2)
    return
  }
  const priceInput = tr3.querySelector('.iPrice')
  const descInput = tr3.querySelector('.iDesc')
  // Only fill price from car if no catalog paket is selected
  const paketSelected3 = !!tr3.querySelector('.iPaket')?.value
  if (
    !paketSelected3 &&
    (!priceInput.value || parseFloat(priceInput.value) === 0 || !keepManualDesc)
  ) {
    priceInput.value = chosen.daily_rate
  }
  if (
    !descInput.value.trim() ||
    descInput.dataset.autoFilled === '1' ||
    !keepManualDesc
  ) {
    descInput.value = chosen.label
    descInput.dataset.autoFilled = '1'
  }
  if (tr3.querySelector('.iNeedsDriver')?.checked) prefillDriverCommission(tr3)
  // Auto-check driver if this vehicle has a preset driver rate
  if (chosen.driver_daily_rate > 0) {
    const chk = tr3.querySelector('.iNeedsDriver')
    const commWrap = tr3.querySelector('.iCommWrap')
    if (chk) chk.checked = true
    if (commWrap) commWrap.style.display = 'flex'
    prefillDriverCommission(tr3)
  }
  ercalc(id2)
}

function eOnDaysChange (id2) {
  const tr3 = document.getElementById(id2)
  if (!tr3) return
  const svc2 = tr3.querySelector('.iSvc')?.value
  const daysInput = tr3.querySelector('.iDays')
  const days = parseFloat(daysInput.value) || 1
  if (svc2 !== 'motor_rental') {
    tr3.querySelector('.iQty').value = Math.max(1, days)
  }
  ercalc(id2)
  if (tr3.querySelector('.iNeedsDriver')?.checked) prefillDriverCommission(tr3)
}

function eDelRow (id) {
  const el = document.getElementById(id)
  if (el) el.remove()
  eRefreshTotal()
}

function ercalc (id) {
  const tr = document.getElementById(id)
  if (!tr) return
  const svc = tr.querySelector('.iSvc')?.value
  const qty = parseFloat(tr.querySelector('.iQty').value) || 0
  const price = parseFloat(tr.querySelector('.iPrice').value) || 0
  const days =
    svc === 'motor_rental'
      ? parseFloat(tr.querySelector('.iDays')?.value) || 1
      : 1
  tr.querySelector('.iTotal').textContent =
    'Rp ' + Math.round(qty * days * price).toLocaleString('id-ID')
  eRefreshTotal()
}

function eOnTaxRateChange () {
  const sel = document.getElementById('eTaxRate')
  document.getElementById('eCustomTaxWrap').style.display =
    sel.value === 'custom' ? '' : 'none'
  eRefreshTotal()
}

function eRefreshTotal () {
  let s = 0
  document.querySelectorAll('#eItemsBody .hs-item-card').forEach(tr => {
    const svc = tr.querySelector('.iSvc')?.value
    const qty = parseFloat(tr.querySelector('.iQty')?.value) || 0
    const price = parseFloat(tr.querySelector('.iPrice')?.value) || 0
    const days =
      svc === 'motor_rental'
        ? parseFloat(tr.querySelector('.iDays')?.value) || 1
        : 1
    s += qty * days * price
  })
  const sel = document.getElementById('eTaxRate')
  let r = sel
    ? sel.value === 'custom'
      ? parseFloat(document.getElementById('eTaxCustom')?.value) || 0
      : parseFloat(sel.value) || 0
    : 0
  const scRate =
    parseFloat(document.getElementById('eServiceCharge')?.value) || 0
  const discRate = parseFloat(document.getElementById('eDiscount')?.value) || 0
  const sc = s * (scRate / 100),
    disc = s * (discRate / 100)
  const afterCD = s + sc - disc
  const tax = afterCD * (r / 100),
    tot = afterCD + tax
  const fmt = v => 'Rp ' + Math.round(v).toLocaleString('id-ID')
  document.getElementById('etpSub').textContent = fmt(s)
  document.getElementById('etpGrand').textContent = fmt(tot)
  document.getElementById('etpScRow').style.display = scRate > 0 ? '' : 'none'
  document.getElementById('etpSc').textContent = fmt(sc) + ' (' + scRate + '%)'
  document.getElementById('etpDiscRow').style.display =
    discRate > 0 ? '' : 'none'
  document.getElementById('etpDisc').textContent =
    '- ' + fmt(disc) + ' (' + discRate + '%)'
  document.getElementById('etpTaxRow').style.display = r > 0 ? '' : 'none'
  document.getElementById('etpTax').textContent = fmt(tax) + ' (' + r + '%)'
}

function submitEdit () {
  const id = document.getElementById('eInvId').value
  const guestName = document.getElementById('eGuestName').value.trim()
  if (!guestName) {
    alert('Nama tamu wajib diisi')
    return
  }
  const rows = document.querySelectorAll('#eItemsBody .hs-item-card')
  if (!rows.length) {
    alert('Minimal 1 item layanan')
    return
  }
  const items = []
  for (const tr of rows) {
    const svc = tr.querySelector('.iSvc').value
    const motorId =
      svc === 'motor_rental'
        ? parseInt(tr.querySelector('.iAsset').value || '0', 10)
        : 0
    const carId =
      svc === 'car_rental'
        ? parseInt(tr.querySelector('.iAsset').value || '0', 10)
        : 0
    const driverCarId = getDriverCarId(tr, svc)
    const commType = tr.querySelector('.iCommType')?.value || 'percent'
    const commValue = parseFloat(tr.querySelector('.iCommValue')?.value) || 0
    // Allow driver split without specific car when catalog commission is set
    const needsDriver =
      usesDriverPayment(svc) &&
      !!tr.querySelector('.iNeedsDriver')?.checked &&
      (driverCarId > 0 || commValue > 0)
    const daysInput = tr.querySelector('.iDays')
    const days = daysInput ? parseFloat(daysInput.value) || 1 : 1
    const tripType = tr.querySelector('.iTripType')?.value || ''
    const guideId = parseInt(tr.querySelector('.iGuide')?.value || '0', 10)

    // Generate start and end dates from days
    const today = new Date()
    const startDt = today.toISOString().slice(0, 19).replace('T', ' ')
    const endDate = new Date(today)
    endDate.setDate(endDate.getDate() + days)
    const endDt = endDate.toISOString().slice(0, 19).replace('T', ' ')

    if (svc === 'motor_rental' && !motorId) {
      alert('Item rental motor wajib pilih armada')
      return
    }
    if (svc === 'narayana_trip') {
      if (!tripType) {
        alert('Narayana Trip wajib pilih tipe trip (Open/Private)')
        return
      }
      if (!guideId) {
        alert('Narayana Trip wajib pilih nama guide')
        return
      }
    }
    items.push({
      service_type: svc,
      description: tr.querySelector('.iDesc').value.trim(),
      // Keep same pricing formula as create: total units = jumlah motor x hari sewa.
      qty:
        svc === 'motor_rental'
          ? (parseFloat(tr.querySelector('.iQty').value) || 1) *
            (parseFloat(tr.querySelector('.iDays')?.value) || 1)
          : parseFloat(tr.querySelector('.iQty').value) || 1,
      motor_count:
        svc === 'motor_rental'
          ? parseFloat(tr.querySelector('.iQty').value) || 1
          : 1,
      unit_price: parseFloat(tr.querySelector('.iPrice').value) || 0,
      motor_id: motorId || null,
      car_id: (svc === 'car_rental' ? carId : driverCarId) || null,
      needs_driver_payment: needsDriver ? 1 : 0,
      commission_type: commType,
      commission_value: commValue,
      trip_type: svc === 'narayana_trip' ? tripType : null,
      guide_id: svc === 'narayana_trip' ? guideId : null,
      guide_name:
        svc === 'narayana_trip'
          ? tr.querySelector('.iGuide')?.selectedOptions?.[0]?.textContent || ''
          : null,
      start_dt: svc === 'motor_rental' || svc === 'car_rental' ? startDt : null,
      end_dt: svc === 'motor_rental' || svc === 'car_rental' ? endDt : null,
      deposit: parseFloat(tr.querySelector('.iDeposit').value) || 0,
      trip_destination: tr.querySelector('.iDest').value.trim() || null
    })
  }
  const sel = document.getElementById('eTaxRate')
  const taxR =
    sel.value === 'custom'
      ? parseFloat(document.getElementById('eTaxCustom')?.value) || 0
      : parseFloat(sel.value) || 0
  const btn = document.getElementById('editBtn')
  btn.disabled = true
  btn.textContent = 'Menyimpan...'
  const fd = new FormData()
  fd.append('action', 'update_invoice')
  fd.append('id', id)
  fd.append('guest_name', guestName)
  fd.append('guest_phone', document.getElementById('ePhone').value.trim())
  fd.append('room_number', document.getElementById('eRoom').value.trim())
  fd.append('payment_method', document.getElementById('ePayMethod').value)
  fd.append('paid_amount', document.getElementById('ePaid').value || 0)
  fd.append('tax_rate', taxR)
  fd.append(
    'service_charge_rate',
    document.getElementById('eServiceCharge').value || 0
  )
  fd.append('discount_rate', document.getElementById('eDiscount').value || 0)
  fd.append('notes', document.getElementById('eNotes').value.trim())
  fd.append('items', JSON.stringify(items))
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        closeEditModal()
        location.reload()
      } else {
        alert('Error: ' + (res.message || 'Unknown'))
        btn.disabled = false
        btn.textContent = '💾 Simpan Perubahan'
      }
    })
    .catch(() => {
      alert('Network error')
      btn.disabled = false
      btn.textContent = '💾 Simpan Perubahan'
    })
}

// ── SERVICE TYPE MANAGEMENT ──────────────────────────────────────────────────
let stRowCnt = 0

function addSvcTypeRow () {
  stRowCnt++
  const id = 'new_' + stRowCnt
  const tr = document.createElement('tr')
  tr.id = 'str' + id
  tr.innerHTML =
    `<td><input type="text" class="stIcon" value="🔹" style="width:40px;text-align:center"></td>` +
    `<td><input type="text" class="stKey" placeholder="e.g. spa_treatment"></td>` +
    `<td><input type="text" class="stLabel" placeholder="e.g. Spa Treatment"></td>` +
    `<td><input type="number" class="stSort" value="0" style="width:45px"></td>` +
    `<td style="display:flex;gap:3px">` +
    `<button class="btn-cat-save" onclick="saveSvcType('${id}')">💾</button>` +
    `<button class="btn-cat-del" onclick="document.getElementById('str${id}').remove()">✕</button>` +
    `</td>`
  document.getElementById('svcTypeBody').prepend(tr)
}

function saveSvcType (stId) {
  const tr = document.getElementById('str' + stId)
  if (!tr) return
  const fd = new FormData()
  fd.append('action', 'save_service_type')
  fd.append('st_id', isNaN(stId) ? 0 : stId)
  fd.append('type_icon', tr.querySelector('.stIcon').value.trim() || '🔹')
  fd.append('type_key', tr.querySelector('.stKey').value.trim())
  fd.append('type_label', tr.querySelector('.stLabel').value.trim())
  fd.append('sort_order', tr.querySelector('.stSort').value || 0)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        tr.id = 'str' + res.id
        tr.querySelectorAll('button')[0].setAttribute(
          'onclick',
          'saveSvcType(' + res.id + ')'
        )
        tr.querySelectorAll('button')[1].setAttribute(
          'onclick',
          'deleteSvcType(' + res.id + ')'
        )
        tr.style.background = '#f0fdf4'
        setTimeout(() => (tr.style.background = ''), 1500)
        alert(
          '✅ Tipe layanan tersimpan! Refresh halaman untuk melihat perubahan di dropdown.'
        )
      } else {
        alert('Error: ' + (res.message || 'failed'))
      }
    })
}

function deleteSvcType (stId) {
  if (!confirm('Hapus tipe layanan ini?')) return
  const fd = new FormData()
  fd.append('action', 'delete_service_type')
  fd.append('st_id', stId)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        const el = document.getElementById('str' + stId)
        if (el) el.remove()
      } else alert('Error: ' + (res.message || 'Cannot delete'))
    })
}

// ── NARAYANA TRIP GUIDE MANAGEMENT ──────────────────────────────────────────
let guideRowCnt = 0

function addGuideRow () {
  guideRowCnt++
  const id = 'new_' + guideRowCnt
  const tr = document.createElement('tr')
  tr.id = 'gtr' + id
  tr.innerHTML =
    `<td><input type="text" class="gName" placeholder="Nama guide"></td>` +
    `<td><input type="text" class="gPhone" placeholder="08xx..."></td>` +
    `<td><input type="number" class="gSort" value="0" style="width:45px"></td>` +
    `<td style="display:flex;gap:3px">` +
    `<button class="btn-cat-save" onclick="saveGuideRow('${id}')">💾</button>` +
    `<button class="btn-cat-del" onclick="document.getElementById('gtr${id}').remove()">✕</button>` +
    `</td>`
  document.getElementById('guideBody').prepend(tr)
}

function saveGuideRow (guideId) {
  const tr = document.getElementById('gtr' + guideId)
  if (!tr) return
  const fd = new FormData()
  fd.append('action', 'save_trip_guide')
  fd.append('guide_id', isNaN(guideId) ? 0 : guideId)
  fd.append('guide_name', tr.querySelector('.gName').value.trim())
  fd.append('phone', tr.querySelector('.gPhone').value.trim())
  fd.append('sort_order', tr.querySelector('.gSort').value || 0)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        tr.id = 'gtr' + res.id
        tr.querySelectorAll('button')[0].setAttribute(
          'onclick',
          'saveGuideRow(' + res.id + ')'
        )
        tr.querySelectorAll('button')[1].setAttribute(
          'onclick',
          'deleteGuideRow(' + res.id + ')'
        )
        tr.style.background = '#f0fdf4'
        setTimeout(() => (tr.style.background = ''), 1500)
        syncTripGuidesFromTable()
        refreshGuideDropdowns()
      } else {
        alert('Error: ' + (res.message || 'failed'))
      }
    })
}

function deleteGuideRow (guideId) {
  if (!confirm('Hapus guide ini?')) return
  const fd = new FormData()
  fd.append('action', 'delete_trip_guide')
  fd.append('guide_id', guideId)
  fetch('hotel-services.php', {
    method: 'POST',
    body: fd,
    credentials: 'include'
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        const el = document.getElementById('gtr' + guideId)
        if (el) el.remove()
        syncTripGuidesFromTable()
        refreshGuideDropdowns()
      } else {
        alert('Error: ' + (res.message || 'Cannot delete'))
      }
    })
}

syncTripGuidesFromTable()
// Expose key functions globally (required for onclick handlers in HTML)
window.openCreateModal = openCreateModal
window.closeCreateModal = closeCreateModal
window.openEditModal = openEditModal
window.closeEditModal = closeEditModal
window.openPayModal = openPayModal
window.closePayModal = closePayModal
window.toggleSplitPay = toggleSplitPay
window.openSettingsModal = openSettingsModal
window.closeSettingsModal = closeSettingsModal
window.addItemRow = addItemRow
window.delRow = delRow
window.onSvcChange = onSvcChange
window.onMotorSourceChange = onMotorSourceChange
window.onRentalAssetChange = onRentalAssetChange
window.onPaketChange = onPaketChange
window.onDaysChange = onDaysChange
window.onNeedsDriverChange = onNeedsDriverChange
window.onDriverCarChange = onDriverCarChange
window.rcalc = rcalc
window.submitCreate = submitCreate
window.submitPay = submitPay
window.submitEdit = submitEdit
window.deleteInvoice = deleteInvoice
window.setGuestMode = setGuestMode
window.saveSettings = saveSettings
window.switchTab = switchTab
window.addCatalogRow = addCatalogRow
window.saveCatalogRow = saveCatalogRow
window.deleteCatalogRow = deleteCatalogRow
window.addSvcTypeRow = addSvcTypeRow
window.saveSvcType = saveSvcType
window.deleteSvcType = deleteSvcType
window.editInvoice = editInvoice
window.printInvoice = printInvoice
console.log('[hotel-services] loaded OK, addItemRow:', typeof addItemRow)
