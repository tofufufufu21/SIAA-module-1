/* ============================================================
   main.js — Module 1 page logic
   Depends on: app.js (ApiClient, Toast, Modal, TableRenderer, etc.)
   ============================================================ */

'use strict';

// ── STATE ────────────────────────────────────────────────────
const state = {
  page:         'dashboard',
  assetPage:    1,
  stockPage:    1,
  currentAssetId: null,
};

// ══════════════════════════════════════════════════════════════
//  NAVIGATION
// ══════════════════════════════════════════════════════════════
function navigate(page) {
  state.page = page;
  document.querySelectorAll('.nav-item').forEach(n => n.classList.toggle('active', n.dataset.page === page));
  document.querySelectorAll('.page-view').forEach(v => v.classList.toggle('active', v.id === 'page-' + page));
  document.getElementById('tb-title').textContent = { dashboard:'Dashboard', assets:'Asset Master', stock:'Stock Room' }[page] || page;
  ({ dashboard: loadDashboard, assets: () => loadAssets(1), stock: () => loadStock(1) }[page] || (() => {}))();
}

// ══════════════════════════════════════════════════════════════
//  DASHBOARD
// ══════════════════════════════════════════════════════════════
async function loadDashboard() {
  const r = await ApiClient.get('/dashboard/stats');
  if (!r.success) return;
  const d = r.data;
  FormHelper.setText('d-total',    d.total_assets);
  FormHelper.setText('d-pm',       '95%');
  FormHelper.setText('d-lowstock', d.low_stock_count ?? '—');
  FormHelper.setText('d-repair',   d.under_repair   ?? '—');
  FormHelper.setText('d-instock',  d.in_stock        ?? '—');
  FormHelper.setText('d-value',    peso(d.total_value));
  FormHelper.setText('d-skus',     d.total_skus     ?? '—');
  FormHelper.setText('d-stockout', d.stockout_count ?? '—');
}

// ══════════════════════════════════════════════════════════════
//  ASSET MASTER
// ══════════════════════════════════════════════════════════════
async function loadAssets(page = 1) {
  state.assetPage = page;
  const params = {
    page,
    per_page:      15,
    search:        FormHelper.val('a-search'),
    status:        FormHelper.val('a-filter-status'),
    category_id:   FormHelper.val('a-filter-cat'),
    department_id: FormHelper.val('a-filter-dept'),
    location_id:   FormHelper.val('a-filter-loc'),
  };
  const r = await ApiClient.get('/asset/list' + ApiClient.qs(params));
  if (!r.success) { Toast.show('error', 'Error', r.message); return; }

  const { items, total, total_pages } = r.data;
  document.getElementById('a-count').textContent = `Showing ${items.length} of ${total} assets`;

  TableRenderer.render('a-tbody', items, assetRow, 10);
  TableRenderer.pagination('a-pagination', r.data, loadAssets);
}

function assetRow(a) {
  const warnCls = warrantyWarning(a.warranty_end) ? 'text-red' : 'td-muted';
  return `<tr>
    <td><input type="checkbox" class="row-cb" value="${a.id}"></td>
    <td class="fw-600">${esc(a.asset_tag)}</td>
    <td class="td-muted">${esc(a.serial_number || '—')}</td>
    <td>${esc(a.category_name || '—')}</td>
    <td>${esc(a.model || '—')}</td>
    <td>${statusBadge(a.status)}</td>
    <td>${esc(a.assigned_user_name || '—')}</td>
    <td>${esc(a.department_name || '—')}</td>
    <td class="${warnCls}">${a.warranty_end || '—'}</td>
    <td>
      <div style="display:flex;gap:5px">
        <button class="btn btn-secondary btn-xs" onclick="viewAsset(${a.id})">View</button>
        <button class="btn btn-dark btn-xs"      onclick="editAsset(${a.id})">Edit</button>
        <button class="btn btn-danger btn-xs"    onclick="openTransfer(${a.id})">Transfer</button>
      </div>
    </td>
  </tr>`;
}

// ── ADD ASSET ─────────────────────────────────────────────────
async function openAddAsset() {
  state.currentAssetId = null;
  document.getElementById('modal-asset-title').textContent = 'ADD NEW ASSET';
  FormHelper.reset('form-asset');
  document.getElementById('a-id').value = '';
  resetModalTabs('modal-asset');
  await loadAssetSelects();
  document.getElementById('attach-list').innerHTML = '<p class="text-muted" style="font-size:13px">Save the asset first to upload documents.</p>';
  Modal.open('modal-asset');
}

async function loadAssetSelects() {
  await Promise.all([
    Lookup.populate('a-category_id', 'categories'),
    Lookup.populate('a-vendor_id',   'vendors'),
    Lookup.populate('a-user_id',     'users',       { labelFn: i => i.full_name }),
    Lookup.populate('a-department',  'departments', { blank: 'Unassigned' }),
    Lookup.populate('a-location',    'locations',   { labelFn: i => (i.site_name ? i.site_name + ' › ' : '') + i.name }),
  ]);
}

// ── EDIT ASSET ────────────────────────────────────────────────
async function editAsset(id) {
  const r = await ApiClient.get('/asset/view' + ApiClient.qs({ id }));
  if (!r.success) { Toast.show('error', 'Error', r.message); return; }
  const a = r.data;
  state.currentAssetId = id;

  document.getElementById('modal-asset-title').textContent = `EDIT ASSET  |  ${a.asset_tag}`;
  resetModalTabs('modal-asset');
  await loadAssetSelects();

  FormHelper.populate({
    'a-id':               a.id,
    'a-asset_tag':        a.asset_tag,
    'a-serial_number':    a.serial_number,
    'a-category_id':      a.category_id,
    'a-make':             a.make,
    'a-model':            a.model,
    'a-os':               a.os,
    'a-firmware':         a.firmware_version,
    'a-status':           a.status,
    'a-specs':            a.notes,
    'a-vendor_id':        a.vendor_id,
    'a-po_number':        a.po_number,
    'a-invoice_number':   a.invoice_number,
    'a-purchase_cost':    a.purchase_cost,
    'a-date_acquired':    a.date_acquired,
    'a-warranty_start':   a.warranty_start,
    'a-warranty_end':     a.warranty_end,
    'a-sla_tier':         a.sla_tier,
    'a-support_contact':  a.support_contract_ref,
    'a-user_id':          a.assigned_user_id,
    'a-department':       a.department_id,
    'a-location':         a.location_id,
    'a-cost_center':      a.cost_center,
    'a-parent_asset':     a.parent_asset_tag,
  });

  loadAttachments(id);
  Modal.open('modal-asset');
}

// ── SAVE ASSET ────────────────────────────────────────────────
async function saveAsset() {
  const id   = FormHelper.val('a-id');
  const body = {
    id:                  id || undefined,
    asset_tag:           FormHelper.val('a-asset_tag'),
    serial_number:       FormHelper.val('a-serial_number'),
    category_id:         FormHelper.val('a-category_id'),
    make:                FormHelper.val('a-make'),
    model:               FormHelper.val('a-model'),
    os:                  FormHelper.val('a-os'),
    firmware_version:    FormHelper.val('a-firmware'),
    status:              FormHelper.val('a-status') || 'In-Stock',
    notes:               FormHelper.val('a-specs'),
    vendor_id:           FormHelper.val('a-vendor_id'),
    po_number:           FormHelper.val('a-po_number'),
    invoice_number:      FormHelper.val('a-invoice_number'),
    purchase_cost:       FormHelper.val('a-purchase_cost'),
    date_acquired:       FormHelper.val('a-date_acquired'),
    warranty_start:      FormHelper.val('a-warranty_start'),
    warranty_end:        FormHelper.val('a-warranty_end'),
    sla_tier:            FormHelper.val('a-sla_tier'),
    support_contract_ref:FormHelper.val('a-support_contact'),
    assigned_user_id:    FormHelper.val('a-user_id'),
    department_id:       FormHelper.val('a-department'),
    location_id:         FormHelper.val('a-location'),
    cost_center:         FormHelper.val('a-cost_center'),
  };

  const url = id ? '/asset/update' : '/asset/create';
  const r   = await ApiClient.post(url, body);

  if (r.success) {
    Toast.show('success', id ? 'Asset Updated' : 'Asset Created');
    Modal.close('modal-asset');
    Lookup.clearCache();
    loadAssets(state.assetPage);
  } else {
    Toast.show('error', 'Save Failed', r.message);
  }
}

// ── VIEW ASSET ────────────────────────────────────────────────
async function viewAsset(id) {
  const r = await ApiClient.get('/asset/view' + ApiClient.qs({ id }));
  if (!r.success) { Toast.show('error', 'Error', r.message); return; }
  const a = r.data;
  state.currentAssetId = id;

  document.querySelector('#modal-view-asset .modal-hdr-title').textContent = `VIEW ASSET  |  ${a.asset_tag}`;

  const statusEl = document.getElementById('vw-status-badge');
  statusEl.className = 'badge ' + { 'In-Use':'b-in-use','In-Stock':'b-in-stock','Under Repair':'b-under-repair','Retired':'b-retired','Disposed':'b-disposed','Lost':'b-lost' }[a.status];
  statusEl.textContent = a.status;
  document.getElementById('vw-cat-badge').textContent = a.category_name || '—';

  const setText = FormHelper.setText.bind(FormHelper);
  setText('vw-serial',    a.serial_number);
  setText('vw-brand',     (a.make || '—') + ' / ' + (a.model || '—'));
  setText('vw-category',  a.category_name);
  setText('vw-os',        a.os);
  setText('vw-firmware',  a.firmware_version);
  setText('vw-spec',      a.notes);
  setText('vw-vendor',    a.vendor_name);
  setText('vw-po',        (a.po_number || '—') + ' / ' + (a.invoice_number || '—'));
  setText('vw-cost',      a.purchase_cost ? peso(a.purchase_cost) : '—');
  setText('vw-acquired',  a.date_acquired);
  setText('vw-warranty',  a.warranty_start && a.warranty_end ? `${a.warranty_start} → ${a.warranty_end}` : '—');
  setText('vw-sla',       a.sla_tier);
  setText('vw-assigned',  a.assigned_user_name);
  setText('vw-dept',      a.department_name);
  setText('vw-location',  a.location_full || a.location_name);
  setText('vw-cc',        a.cost_center);

  // Timeline
  const log = a.lifecycle_log || [];
  document.getElementById('vw-timeline').innerHTML = log.length
    ? log.map(l => `<div class="timeline-item"><div class="tl-dot"></div><div><div class="tl-label">${esc(l.action_type)}${l.to_status ? ' → ' + l.to_status : ''}${l.reason ? ' — ' + l.reason : ''}</div><div class="tl-date">${l.performed_at} · ${esc(l.performed_by_name || '—')}</div></div></div>`).join('')
    : '<p class="text-muted" style="font-size:13px">No history yet.</p>';

  Modal.open('modal-view-asset');
}

function openEditFromView()     { Modal.close('modal-view-asset'); editAsset(state.currentAssetId); }
function openTransferFromView() { Modal.close('modal-view-asset'); openTransfer(state.currentAssetId); }

// ── TRANSFER ASSET ─────────────────────────────────────────────
async function openTransfer(id) {
  state.currentAssetId = id;
  const r = await ApiClient.get('/asset/view' + ApiClient.qs({ id }));
  if (!r.success) return;
  const a = r.data;

  document.getElementById('tf-asset-tag').textContent  = a.asset_tag;
  const sb = document.getElementById('tf-status-badge');
  sb.className = 'badge ' + ({ 'In-Use':'b-in-use','In-Stock':'b-in-stock','Under Repair':'b-under-repair','Retired':'b-retired' }[a.status] || 'b-disposed');
  sb.textContent = a.status;
  document.getElementById('tf-cat-badge').textContent   = a.category_name || '—';
  FormHelper.set('tf-current', a.assigned_user_name || 'Unassigned');
  FormHelper.set('tf-custodian', '');
  FormHelper.set('tf-reason', '');
  document.getElementById('tf-signoff').checked = false;

  await Promise.all([
    Lookup.populate('tf-department', 'departments', { blank: 'Select department…' }),
    Lookup.populate('tf-location',   'locations',   { blank: 'Select location…', labelFn: i => (i.site_name ? i.site_name + ' › ' : '') + i.name }),
  ]);
  Modal.open('modal-transfer');
}

async function confirmTransfer() {
  if (!document.getElementById('tf-signoff').checked) {
    Toast.show('warning', 'Sign-off Required', 'Please confirm the new custodian has acknowledged.');
    return;
  }
  const r = await ApiClient.post('/asset/transfer', {
    asset_id:        state.currentAssetId,
    to_department_id:FormHelper.val('tf-department') || null,
    to_location_id:  FormHelper.val('tf-location')   || null,
    reason:          FormHelper.val('tf-reason'),
  });
  if (r.success) {
    Toast.show('success', 'Transfer Complete');
    Modal.close('modal-transfer');
    loadAssets(state.assetPage);
  } else {
    Toast.show('error', 'Transfer Failed', r.message);
  }
}

// ── EXPORT ────────────────────────────────────────────────────
async function exportAssets() {
  const r = await ApiClient.get('/asset/list' + ApiClient.qs({ per_page: 9999 }));
  if (!r.success) return;
  const rows = [
    ['Asset Tag','Serial No.','Category','Make','Model','Status','Assigned To','Dept','Location','Warranty End'],
    ...r.data.items.map(a => [a.asset_tag, a.serial_number, a.category_name, a.make, a.model, a.status, a.assigned_user_name, a.department_name, a.location_full, a.warranty_end])
  ];
  const csv  = rows.map(r => r.map(c => `"${(c||'').toString().replace(/"/g,'""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const a    = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'assets_export.csv';
  a.click();
  Toast.show('success', 'Exported', 'assets_export.csv downloaded.');
}

// ── ATTACHMENTS ───────────────────────────────────────────────
async function loadAttachments(assetId) {
  const r = await ApiClient.get('/attachment/list' + ApiClient.qs({ asset_id: assetId }));
  const el = document.getElementById('attach-list');
  if (!r.success || !r.data?.length) {
    el.innerHTML = '<p class="text-muted" style="font-size:13px">No attachments yet.</p>';
    return;
  }
  el.innerHTML = r.data.map(a => `
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
      <span style="font-size:18px">${fileIcon(a.file_type)}</span>
      <div style="flex:1;min-width:0">
        <a href="/attachment/download?id=${a.id}" target="_blank" style="font-size:13px;color:var(--teal);text-decoration:none;font-weight:600">${esc(a.file_name)}</a>
        <div style="font-size:11px;color:var(--text-3)">${esc(a.label)} · ${esc(a.file_size_kb || '')}</div>
      </div>
      <button class="icon-btn" onclick="deleteAttach(${a.id})" title="Delete">✕</button>
    </div>`).join('');
}

async function uploadAttachment() {
  const id = FormHelper.val('a-id') || state.currentAssetId;
  if (!id) { Toast.show('warning', 'Save First', 'Save the asset before uploading files.'); return; }
  const fi = document.getElementById('attach-file');
  if (!fi?.files?.length) { Toast.show('warning', 'No File', 'Please select a file.'); return; }
  const fd = new FormData();
  fd.append('file',        fi.files[0]);
  fd.append('asset_id',    id);
  fd.append('label',       FormHelper.val('attach-label'));
  fd.append('uploaded_by', 1);
  const r = await ApiClient.upload('/attachment/upload', fd);
  if (r.success) { Toast.show('success', 'Uploaded', fi.files[0].name); fi.value = ''; loadAttachments(id); }
  else Toast.show('error', 'Upload Failed', r.message);
}

async function deleteAttach(attachId) {
  Modal.confirm('Delete Attachment', 'This cannot be undone.', async () => {
    const r = await ApiClient.post('/attachment/delete', { id: attachId });
    if (r.success) { Toast.show('success', 'Deleted'); loadAttachments(state.currentAssetId); }
    else Toast.show('error', 'Error', r.message);
  });
}

// ══════════════════════════════════════════════════════════════
//  STOCK ROOM
// ══════════════════════════════════════════════════════════════
async function loadStock(page = 1) {
  state.stockPage = page;
  const params = { page, per_page: 15, search: FormHelper.val('s-search'), category_id: FormHelper.val('s-filter-cat') };
  const [rItems, rLow] = await Promise.all([
    ApiClient.get('/stock/list' + ApiClient.qs(params)),
    ApiClient.get('/stock/low'),
  ]);
  if (!rItems.success) return;

  const lowIds    = new Set((rLow.data || []).map(i => i.id));
  const stockouts = (rLow.data || []).filter(i => parseFloat(i.quantity_on_hand) <= 0);

  // Stats
  const allR = await ApiClient.get('/stock/list' + ApiClient.qs({ per_page: 9999 }));
  let totalVal = 0;
  (allR.data?.items || []).forEach(i => { totalVal += parseFloat(i.unit_cost || 0) * parseFloat(i.total_qty_on_hand || 0); });

  FormHelper.setText('s-total-value', peso(totalVal));
  FormHelper.setText('s-skus',        allR.data?.total ?? '—');
  FormHelper.setText('s-below-rop',   rLow.data?.length ?? 0);
  FormHelper.setText('s-stockout',    stockouts.length);

  const { items, total, total_pages } = rItems.data;

  TableRenderer.render('s-tbody', items, i => stockRow(i, lowIds), 8);
  TableRenderer.pagination('s-pagination', rItems.data, loadStock);
}

function stockRow(i, lowIds) {
  const qty   = parseFloat(i.total_qty_on_hand || 0);
  const isOut = qty <= 0;
  const isLow = lowIds.has(i.id);
  let statusHtml = `<span class="badge b-in-stock">In stock</span>`;
  if (isOut)      statusHtml = `<span class="badge b-stockout">Stockout</span>`;
  else if (isLow) statusHtml = `<span class="badge b-below-rop">Below ROP</span>`;
  return `<tr>
    <td class="fw-600">${esc(i.item_code)}</td>
    <td>${esc(i.name)}</td>
    <td class="fw-600">${qty.toFixed(0)}</td>
    <td class="td-muted">—</td>
    <td class="td-muted">—</td>
    <td class="td-muted">—</td>
    <td>${statusHtml}</td>
    <td>
      <div style="display:flex;gap:5px;align-items:center">
        <button class="icon-btn" onclick="viewStockItem(${i.id})" title="View"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
        <button class="icon-btn" onclick="editStockItem(${i.id})" title="Edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
        <button class="icon-btn" title="More"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></button>
      </div>
    </td>
  </tr>`;
}

async function openAddStockItem() {
  state.currentStockId = null;
  document.getElementById('modal-stock-title').textContent = 'Add Item';
  FormHelper.reset('form-stock');
  FormHelper.set('si-id', '');
  await Lookup.populate('si-category', 'categories');
  Modal.open('modal-stock-item');
}

async function editStockItem(id) {
  const r = await ApiClient.get('/item/list' + ApiClient.qs({ id }));
  // Actually get single item
  const r2 = await ApiClient.get('/stock/list' + ApiClient.qs({ per_page: 999 }));
  const item = r2.data?.items?.find(i => i.id === id);
  if (!item) return;
  document.getElementById('modal-stock-title').textContent = 'Edit Item';
  await Lookup.populate('si-category', 'categories');
  FormHelper.populate({ 'si-id': item.id, 'si-item_code': item.item_code, 'si-name': item.name, 'si-category': item.category_id, 'si-uom': item.unit_of_measure, 'si-description': item.description });
  Modal.open('modal-stock-item');
}

async function saveStockItem() {
  const id   = FormHelper.val('si-id');
  const body = { item_code: FormHelper.val('si-item_code'), name: FormHelper.val('si-name'), category_id: FormHelper.val('si-category'), unit_of_measure: FormHelper.val('si-uom') || 'pcs', min_level: FormHelper.val('si-min'), max_level: FormHelper.val('si-max'), reorder_point: FormHelper.val('si-rop'), description: FormHelper.val('si-description') };
  const url  = id ? '/item/update' : '/item/create';
  if (id) body.id = id;
  const r = await ApiClient.post(url, body);
  if (r.success) { Toast.show('success', id ? 'Item Updated' : 'Item Added'); Modal.close('modal-stock-item'); loadStock(state.stockPage); }
  else Toast.show('error', 'Failed', r.message);
}

async function viewStockItem(id) {
  Toast.show('info', 'View Item', 'Full item detail modal — connects to stock ledger.');
}

// ── ISSUE STOCK ───────────────────────────────────────────────
async function openIssueStock() {
  FormHelper.reset('form-issue');
  const allR = await ApiClient.get('/stock/list' + ApiClient.qs({ per_page: 200 }));
  const sel  = document.getElementById('issue-item');
  sel.innerHTML = '<option value="">Select Item</option>';
  (allR.data?.items || []).forEach(i => {
    const o = document.createElement('option');
    o.value = i.id;
    o.dataset.qty = i.total_qty_on_hand || 0;
    o.dataset.uom = i.unit_of_measure   || 'pcs';
    o.textContent = `${i.name} (${i.item_code})`;
    sel.appendChild(o);
  });
  await Promise.all([
    Lookup.populate('issue-location',   'locations',   { blank: 'Select location…', labelFn: i => (i.site_name ? i.site_name + ' › ' : '') + i.name }),
    Lookup.populate('issue-department', 'departments', { blank: 'Select department…' }),
  ]);
  FormHelper.setText('issue-qty-display', '—');
  FormHelper.setText('issue-avail',       '—');
  document.getElementById('issue-oos').classList.add('hidden');
  Modal.open('modal-issue-stock');
}

document.addEventListener('change', e => {
  if (e.target.id === 'issue-item') {
    const opt = e.target.selectedOptions[0];
    FormHelper.setText('issue-qty-display', opt?.dataset?.qty ?? '—');
    FormHelper.setText('issue-avail',       opt?.dataset?.qty ?? '—');
    FormHelper.set('issue-uom-label',       opt?.dataset?.uom || '');
    document.getElementById('issue-oos').classList.add('hidden');
  }
  if (e.target.id === 'issue-qty-input') {
    const opt   = document.getElementById('issue-item').selectedOptions[0];
    const avail = parseFloat(opt?.dataset?.qty || 0);
    const req   = parseFloat(e.target.value || 0);
    document.getElementById('issue-oos').classList.toggle('hidden', req <= avail || !req);
  }
});

async function confirmIssueStock() {
  const body = { item_id: FormHelper.val('issue-item'), location_id: FormHelper.val('issue-location'), quantity: FormHelper.val('issue-qty-input'), reason_code: FormHelper.val('issue-reason'), notes: FormHelper.val('issue-notes') };
  if (!body.item_id || !body.location_id || !body.quantity) { Toast.show('warning', 'Missing Fields'); return; }
  const r = await ApiClient.post('/stock/issue', body);
  if (r.success) { Toast.show('success', 'Stock Issued'); Modal.close('modal-issue-stock'); loadStock(state.stockPage); }
  else Toast.show('error', 'Issue Failed', r.message);
}

// ── RECEIVE STOCK (GRN) ───────────────────────────────────────
async function openReceivedStock() {
  FormHelper.reset('form-grn');
  const allR = await ApiClient.get('/stock/list' + ApiClient.qs({ per_page: 200 }));
  const sel  = document.getElementById('grn-item');
  sel.innerHTML = '<option value="">Select Item</option>';
  (allR.data?.items || []).forEach(i => { const o = document.createElement('option'); o.value = i.id; o.textContent = `${i.name} (${i.item_code})`; sel.appendChild(o); });
  await Lookup.populate('grn-location', 'locations', { blank: 'Select location…', labelFn: i => (i.site_name ? i.site_name + ' › ' : '') + i.name });
  Modal.open('modal-grn');
}

async function confirmGRN() {
  const body = { item_id: FormHelper.val('grn-item'), location_id: FormHelper.val('grn-location'), quantity: FormHelper.val('grn-qty'), notes: FormHelper.val('grn-notes') };
  if (!body.item_id || !body.location_id || !body.quantity) { Toast.show('warning', 'Missing Fields'); return; }
  const r = await ApiClient.post('/stock/receive', body);
  if (r.success) { Toast.show('success', 'Stock Received'); Modal.close('modal-grn'); loadStock(state.stockPage); }
  else Toast.show('error', 'GRN Failed', r.message);
}

// ══════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════
function resetModalTabs(modalId) {
  const m = document.getElementById(modalId);
  m?.querySelectorAll('.modal-tab').forEach((t, i)  => t.classList.toggle('active', i === 0));
  m?.querySelectorAll('.tab-panel').forEach((p, i)  => p.classList.toggle('hidden', i !== 0));
}

function clearAssetFilters() {
  ['a-search','a-filter-status','a-filter-cat','a-filter-dept','a-filter-loc'].forEach(id => FormHelper.set(id, ''));
  loadAssets(1);
}

// ══════════════════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', async () => {
  document.querySelectorAll('.nav-item[data-page]').forEach(n => n.addEventListener('click', () => navigate(n.dataset.page)));
  Modal.initTabs('modal-asset');
  document.getElementById('today-date').textContent = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

  // Global search
  document.getElementById('global-search')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { FormHelper.set('a-search', e.target.value.trim()); navigate('assets'); }
  });

  // Populate filter dropdowns
  await Promise.all([
    Lookup.populate('a-filter-cat',  'categories',  { blank: 'All' }),
    Lookup.populate('a-filter-dept', 'departments', { blank: 'All' }),
    Lookup.populate('a-filter-loc',  'locations',   { blank: 'All', labelFn: i => (i.site_name ? i.site_name + ' › ' : '') + i.name }),
    Lookup.populate('s-filter-cat',  'categories',  { blank: 'All Categories' }),
  ]);

  // Topbar hamburger (mobile)
  document.querySelector('.tb-hamburger')?.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));

  // Select-all checkbox
  document.getElementById('cb-all-assets')?.addEventListener('change', function() {
    document.querySelectorAll('#a-tbody .row-cb').forEach(cb => cb.checked = this.checked);
  });

  navigate('dashboard');
});
