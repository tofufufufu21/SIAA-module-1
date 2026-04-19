/* ============================================================
   IT&DS Asset & Inventory — Main JavaScript
   Module 1: Asset Master + Stock Room
   ============================================================ */

'use strict';

// ── CONFIG ───────────────────────────────────────────────────
const API = {
  assets:     'api/assets.php',
  stock:      'api/stock_items.php',
  txn:        'api/stock_transactions.php',
  cycles:     'api/cycle_counts.php',
  lookups:    'api/lookups.php',
  attach:     'api/attachments.php',
};
const CURRENT_USER = 1; // Replace with session-based user ID

// ── STATE ────────────────────────────────────────────────────
const state = {
  currentPage: 'dashboard',
  assetPage: 1,
  stockPage: 1,
  lookupCache: {},
  currentAssetId: null,
  currentStockId: null,
};

// ══════════════════════════════════════════════════════════════
//  API HELPERS
// ══════════════════════════════════════════════════════════════

async function apiFetch(url, opts = {}) {
  try {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json' },
      ...opts,
    });
    return await res.json();
  } catch (e) {
    toast('error', 'Network Error', 'Could not reach server.');
    return { success: false, message: 'Network error' };
  }
}

async function getLookup(resource, params = '') {
  const key = resource + params;
  if (state.lookupCache[key]) return state.lookupCache[key];
  const r = await apiFetch(`${API.lookups}?resource=${resource}${params}`);
  if (r.success) state.lookupCache[key] = r.data;
  return r.data || [];
}

function clearLookupCache() { state.lookupCache = {}; }

async function populateSelect(id, resource, { value = 'id', label = 'name', params = '', blankLabel = 'Select…', filter = null } = {}) {
  const el = document.getElementById(id);
  if (!el) return;
  const items = await getLookup(resource, params);
  const cur = el.value;
  el.innerHTML = `<option value="">${blankLabel}</option>`;
  const list = filter ? items.filter(filter) : items;
  list.forEach(i => {
    const opt = document.createElement('option');
    opt.value = i[value];
    opt.textContent = typeof label === 'function' ? label(i) : (i[label] || i.name || '');
    el.appendChild(opt);
  });
  if (cur) el.value = cur;
}

// ══════════════════════════════════════════════════════════════
//  NAVIGATION
// ══════════════════════════════════════════════════════════════

function navigate(page) {
  state.currentPage = page;
  document.querySelectorAll('.nav-item').forEach(n => n.classList.toggle('active', n.dataset.page === page));
  document.querySelectorAll('.page-view').forEach(v => v.classList.toggle('hidden', v.id !== 'page-' + page));
  document.getElementById('topbar-title').textContent = {
    dashboard:   'Dashboard',
    assets:      'Asset Master',
    stock:       'Stock Room',
    cycles:      'Cycle Counts',
  }[page] || page;

  // Load page data
  const loaders = {
    dashboard: loadDashboard,
    assets:    () => loadAssets(1),
    stock:     () => loadStock(1),
    cycles:    () => loadCycles(1),
  };
  loaders[page]?.();
}

// ══════════════════════════════════════════════════════════════
//  TOAST NOTIFICATIONS
// ══════════════════════════════════════════════════════════════

function toast(type, title, msg = '', duration = 3500) {
  const icons = {
    success: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`,
    error:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>`,
    warning: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>`,
    info:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4m0-4h.01"/></svg>`,
  };
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.innerHTML = `${icons[type]}<div class="toast-body"><div class="toast-title">${esc(title)}</div>${msg ? `<div class="toast-msg">${esc(msg)}</div>` : ''}</div><button class="toast-close" onclick="this.closest('.toast').remove()">✕</button>`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), duration);
}

// ══════════════════════════════════════════════════════════════
//  MODAL SYSTEM
// ══════════════════════════════════════════════════════════════

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Tab switching inside a modal
function initModalTabs(modalId) {
  const modal = document.getElementById(modalId);
  if (!modal) return;
  modal.querySelectorAll('.modal-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.tab;
      modal.querySelectorAll('.modal-tab').forEach(t => t.classList.toggle('active', t === tab));
      modal.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== target));
    });
  });
}

// Close modal on overlay click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});

// ESC to close
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
  }
});

// ══════════════════════════════════════════════════════════════
//  CONFIRM DIALOG
// ══════════════════════════════════════════════════════════════

function confirmDialog(title, msg, onConfirm) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-msg').textContent = msg;
  document.getElementById('confirm-ok').onclick = () => { closeModal('modal-confirm'); onConfirm(); };
  openModal('modal-confirm');
}

// ══════════════════════════════════════════════════════════════
//  DASHBOARD
// ══════════════════════════════════════════════════════════════

async function loadDashboard() {
  // Load asset counts by status
  const statuses = ['In-Use', 'In-Stock', 'Under Repair', 'Retired', 'Disposed', 'Lost'];
  let total = 0;
  const counts = {};
  await Promise.all(statuses.map(async s => {
    const r = await apiFetch(`${API.assets}?status=${encodeURIComponent(s)}&per_page=1`);
    counts[s] = r.data?.total || 0;
    total += counts[s];
  }));

  setEl('dash-total',    total);
  setEl('dash-inuse',    counts['In-Use']);
  setEl('dash-instock',  counts['In-Stock']);
  setEl('dash-repair',   counts['Under Repair']);

  const low = await apiFetch(`${API.stock}?action=low_stock`);
  setEl('dash-lowstock', low.data?.length || 0);

  // PM compliance placeholder (connects to Module 2)
  setEl('dash-pm-compliance', '95%');
}

// ══════════════════════════════════════════════════════════════
//  ASSET MASTER
// ══════════════════════════════════════════════════════════════

async function loadAssets(page = 1) {
  state.assetPage = page;
  const search = val('asset-search');
  const status = val('asset-filter-status');
  const cat    = val('asset-filter-cat');
  const dept   = val('asset-filter-dept');
  const loc    = val('asset-filter-loc');

  setEl('asset-table-body', '<tr><td colspan="9" class="table-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg><p>Loading assets…</p></td></tr>');

  const qs = `?page=${page}&per_page=15&search=${enc(search)}&status=${enc(status)}&category_id=${cat}&department_id=${dept}&location_id=${loc}`;
  const r  = await apiFetch(API.assets + qs);
  if (!r.success) { toast('error', 'Error', r.message); return; }

  const { items, total, total_pages } = r.data;
  setEl('asset-count-label', `Showing ${items.length} of ${total} assets`);

  if (!items.length) {
    setEl('asset-table-body', emptyRow(9, 'No assets found.', 'Try adjusting your filters or add a new asset.'));
    renderPagination('asset-pagination', page, total_pages, total, loadAssets);
    return;
  }

  setEl('asset-table-body', items.map(a => `
    <tr>
      <td><input type="checkbox" class="row-check" value="${a.id}"></td>
      <td class="fw-600">${esc(a.asset_tag)}</td>
      <td>${esc(a.serial_number || '—')}</td>
      <td>${esc(a.category_name || '—')}</td>
      <td>${esc(a.model || '—')}</td>
      <td>${statusBadge(a.status)}</td>
      <td>${esc(a.assigned_user_name || '—')}</td>
      <td>${esc(a.department_name || '—')}</td>
      <td class="${isWarrantyExpiring(a.warranty_end) ? 'text-red' : 'text-muted'}">${a.warranty_end || '—'}</td>
      <td>
        <div style="display:flex;gap:5px">
          <button class="btn btn-secondary btn-xs" onclick="viewAsset(${a.id})">View</button>
          <button class="btn btn-dark btn-xs" onclick="editAsset(${a.id})">Edit</button>
          <button class="btn btn-danger btn-xs" onclick="openTransfer(${a.id})">Transfer</button>
        </div>
      </td>
    </tr>`).join(''));

  renderPagination('asset-pagination', page, total_pages, total, loadAssets);
}

function isWarrantyExpiring(date) {
  if (!date) return false;
  return new Date(date) < new Date(Date.now() + 30 * 86400000);
}

// ── OPEN ADD ASSET MODAL ──────────────────────────────────────
async function openAddAsset() {
  state.currentAssetId = null;
  document.getElementById('modal-asset-title').textContent = 'ADD NEW ASSET';
  document.getElementById('form-asset').reset();
  document.getElementById('a-id').value = '';

  // Reset tabs to first
  const modal = document.getElementById('modal-asset');
  modal.querySelectorAll('.modal-tab').forEach((t, i) => t.classList.toggle('active', i === 0));
  modal.querySelectorAll('.tab-panel').forEach((p, i) => p.classList.toggle('hidden', i !== 0));

  // Load selects
  await loadAssetSelects();
  openModal('modal-asset');
}

async function loadAssetSelects() {
  await Promise.all([
    populateSelect('a-category_id', 'categories'),
    populateSelect('a-vendor_id',   'vendors',     { blankLabel: 'Vendor Inc.' }),
    populateSelect('a-user_id',     'users',       { label: 'full_name', blankLabel: 'Search user...' }),
    populateSelect('a-department',  'departments', { blankLabel: 'Unassigned' }),
    populateSelect('a-location',    'locations'),
    populateSelect('a-sla_tier',    null,          null), // handled manually below
  ]);
  // SLA tier is a free-text or fixed options - handled as text input in mockup
}

// ── EDIT ASSET ────────────────────────────────────────────────
async function editAsset(id) {
  const r = await apiFetch(`${API.assets}?id=${id}`);
  if (!r.success) { toast('error', 'Error', r.message); return; }
  const a = r.data;
  state.currentAssetId = id;

  document.getElementById('modal-asset-title').textContent = `EDIT ASSET  |  ${a.asset_tag}`;

  const modal = document.getElementById('modal-asset');
  modal.querySelectorAll('.modal-tab').forEach((t, i) => t.classList.toggle('active', i === 0));
  modal.querySelectorAll('.tab-panel').forEach((p, i) => p.classList.toggle('hidden', i !== 0));

  await loadAssetSelects();

  // Populate all fields
  const map = {
    'a-asset_tag':          a.asset_tag,
    'a-serial_number':      a.serial_number,
    'a-category_id':        a.category_id,
    'a-make':               a.make,
    'a-model':              a.model,
    'a-os':                 a.os,
    'a-firmware':           a.firmware_version,
    'a-status':             a.status,
    'a-specs':              a.notes,
    'a-vendor_id':          a.vendor_id,
    'a-po_number':          a.po_number,
    'a-invoice_number':     a.invoice_number,
    'a-purchase_cost':      a.purchase_cost,
    'a-date_acquired':      a.date_acquired,
    'a-warranty_start':     a.warranty_start,
    'a-warranty_end':       a.warranty_end,
    'a-sla_tier':           a.sla_tier,
    'a-support_contact':    a.support_contract_ref,
    'a-user_id':            a.assigned_user_id,
    'a-department':         a.department_id,
    'a-location':           a.location_id,
    'a-cost_center':        a.cost_center,
    'a-parent_asset':       a.parent_asset_tag,
    'a-id':                 a.id,
  };
  Object.entries(map).forEach(([elId, val]) => {
    const el = document.getElementById(elId);
    if (el && val != null) el.value = val;
  });

  // Load attachments panel
  loadModalAttachments(id);
  openModal('modal-asset');
}

// ── SAVE ASSET ────────────────────────────────────────────────
async function saveAsset() {
  const id = val('a-id');
  const body = {
    asset_tag:           val('a-asset_tag'),
    serial_number:       val('a-serial_number')    || null,
    category_id:         val('a-category_id')      || null,
    make:                val('a-make')             || null,
    model:               val('a-model')            || null,
    os:                  val('a-os')               || null,
    firmware_version:    val('a-firmware')         || null,
    status:              val('a-status')           || 'In-Stock',
    notes:               val('a-specs')            || null,
    vendor_id:           val('a-vendor_id')        || null,
    po_number:           val('a-po_number')        || null,
    invoice_number:      val('a-invoice_number')   || null,
    purchase_cost:       val('a-purchase_cost')    || null,
    date_acquired:       val('a-date_acquired')    || null,
    warranty_start:      val('a-warranty_start')   || null,
    warranty_end:        val('a-warranty_end')     || null,
    sla_tier:            val('a-sla_tier')         || null,
    support_contract_ref:val('a-support_contact')  || null,
    assigned_user_id:    val('a-user_id')          || null,
    department_id:       val('a-department')       || null,
    location_id:         val('a-location')         || null,
    cost_center:         val('a-cost_center')      || null,
    performed_by:        CURRENT_USER,
  };

  const url    = id ? `${API.assets}?id=${id}` : API.assets;
  const method = id ? 'PUT' : 'POST';
  const r = await apiFetch(url, { method, body: JSON.stringify(body) });

  if (r.success) {
    toast('success', id ? 'Asset Updated' : 'Asset Created', id ? 'Changes saved.' : 'New asset registered.');
    closeModal('modal-asset');
    clearLookupCache();
    loadAssets(state.assetPage);
  } else {
    toast('error', 'Save Failed', r.message);
  }
}

// ── VIEW ASSET ────────────────────────────────────────────────
async function viewAsset(id) {
  const r = await apiFetch(`${API.assets}?id=${id}`);
  if (!r.success) { toast('error', 'Error', r.message); return; }
  const a = r.data;
  state.currentAssetId = id;

  document.getElementById('view-asset-tag').textContent = a.asset_tag;
  document.querySelector('#modal-view-asset .modal-header-title').textContent = `VIEW ASSET  |  ${a.asset_tag}`;

  // Status & category badges
  document.getElementById('view-status-badge').className = `badge badge-${a.status.toLowerCase().replace(/[^a-z]/g,'-')}`;
  document.getElementById('view-status-badge').textContent = a.status;
  document.getElementById('view-category-badge').textContent = a.category_name || a.category_id || '—';

  // General info
  setEl('vw-serial',     a.serial_number || '—');
  setEl('vw-brand',      `${a.make || '—'} / ${a.model || '—'}`);
  setEl('vw-category',   a.category_name || '—');
  setEl('vw-os',         a.os || '—');
  setEl('vw-firmware',   a.firmware_version || '—');
  setEl('vw-status2',    a.status);
  setEl('vw-spec',       a.notes || '—');

  // Purchase & warranty
  setEl('vw-vendor',     a.vendor_name || '—');
  setEl('vw-po',         `${a.po_number || '—'} / ${a.invoice_number || '—'}`);
  setEl('vw-cost',       a.purchase_cost ? `₱${parseFloat(a.purchase_cost).toLocaleString('en-PH', {minimumFractionDigits: 0})}` : '—');
  setEl('vw-acquired',   a.date_acquired || '—');
  setEl('vw-warranty',   a.warranty_start && a.warranty_end ? `${a.warranty_start} → ${a.warranty_end}` : '—');
  setEl('vw-sla',        a.sla_tier || '—');

  // Assignment
  setEl('vw-assigned',   a.assigned_user_name || '—');
  setEl('vw-dept',       a.department_name || '—');
  setEl('vw-location',   a.location_full || a.location_name || '—');
  setEl('vw-cost_center',a.cost_center || '—');

  // History timeline
  const logItems = a.lifecycle_log || [];
  const timelineEl = document.getElementById('vw-timeline');
  if (logItems.length) {
    timelineEl.innerHTML = logItems.map(l => `
      <div class="timeline-item">
        <div class="timeline-dot"></div>
        <div>
          <div class="timeline-label">${esc(l.action_type)} ${l.to_status ? '→ ' + l.to_status : ''} ${l.reason ? '— ' + l.reason : ''}</div>
          <div class="timeline-date">${l.performed_at} · ${esc(l.performed_by_name || '—')}</div>
        </div>
      </div>`).join('');
  } else {
    timelineEl.innerHTML = '<p class="text-muted" style="font-size:13px">No history yet.</p>';
  }

  // Maintenance summary (placeholder — Module 3 data)
  setEl('vw-total-tickets', '—');
  setEl('vw-open-tickets',  '—');
  setEl('vw-closed-tickets','—');

  openModal('modal-view-asset');
}

function openEditFromView() {
  closeModal('modal-view-asset');
  editAsset(state.currentAssetId);
}

function openTransferFromView() {
  closeModal('modal-view-asset');
  openTransfer(state.currentAssetId);
}

// ── TRANSFER ASSET ────────────────────────────────────────────
async function openTransfer(id) {
  state.currentAssetId = id;
  const r = await apiFetch(`${API.assets}?id=${id}`);
  if (!r.success) return;
  const a = r.data;

  document.getElementById('transfer-asset-tag').textContent  = a.asset_tag;
  document.getElementById('transfer-status-badge').className = `badge badge-${(a.status||'').toLowerCase().replace(/[^a-z]/g,'-')}`;
  document.getElementById('transfer-status-badge').textContent = a.status;
  document.getElementById('transfer-category-badge').textContent = a.category_name || '—';
  document.getElementById('tf-current-custodian').value = a.assigned_user_name || 'Unassigned';

  document.getElementById('tf-new-custodian').value  = '';
  document.getElementById('tf-new-location').value   = '';
  document.getElementById('tf-reason').value         = '';
  document.getElementById('tf-signoff').checked      = false;

  await populateSelect('tf-new-department', 'departments', { blankLabel: 'Select department…' });
  openModal('modal-transfer');
}

async function confirmTransfer() {
  if (!document.getElementById('tf-signoff').checked) {
    toast('warning', 'Sign-off Required', 'Please confirm the new custodian has signed off.');
    return;
  }
  const body = {
    asset_id:        state.currentAssetId,
    to_user_id:      null, // would resolve name to ID in production
    to_department_id:val('tf-new-department') || null,
    reason:          val('tf-reason'),
    approved_by:     CURRENT_USER,
    performed_by:    CURRENT_USER,
  };
  const r = await apiFetch(`${API.assets}?action=assign`, { method: 'POST', body: JSON.stringify(body) });
  if (r.success) {
    toast('success', 'Transfer Complete', 'Asset has been transferred.');
    closeModal('modal-transfer');
    loadAssets(state.assetPage);
  } else {
    toast('error', 'Transfer Failed', r.message);
  }
}

// ── ATTACHMENTS IN MODAL ──────────────────────────────────────
async function loadModalAttachments(assetId) {
  const el = document.getElementById('attach-list');
  if (!el) return;
  el.innerHTML = '<p style="color:var(--text-muted);font-size:13px">Loading…</p>';
  const r = await apiFetch(`${API.attach}?asset_id=${assetId}`);
  if (!r.success || !r.data.length) {
    el.innerHTML = '<p style="color:var(--text-muted);font-size:13px">No attachments yet.</p>';
    return;
  }
  el.innerHTML = r.data.map(a => `
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
      <span style="font-size:18px">${fileIcon(a.file_type)}</span>
      <div style="flex:1;min-width:0">
        <a href="${esc(a.download_url)}" target="_blank" style="font-size:13px;color:var(--teal);text-decoration:none;font-weight:600">${esc(a.file_name)}</a>
        <div style="font-size:11px;color:var(--text-muted)">${esc(a.label)} · ${esc(a.file_size_kb || '')}</div>
      </div>
      <button class="btn-icon" onclick="deleteAttach(${a.id},${assetId})" title="Delete">✕</button>
    </div>`).join('');
}

function fileIcon(mime) {
  if (mime?.includes('pdf'))   return '📄';
  if (mime?.includes('image')) return '🖼️';
  if (mime?.includes('word'))  return '📝';
  if (mime?.includes('sheet') || mime?.includes('excel')) return '📊';
  return '📎';
}

async function uploadAttachment() {
  const id = val('a-id') || state.currentAssetId;
  if (!id) { toast('warning', 'Save Asset First', 'Please save the asset before uploading files.'); return; }

  const fileInput = document.getElementById('attach-file');
  const label     = val('attach-label');
  if (!fileInput?.files?.length) { toast('warning', 'No File', 'Please select a file.'); return; }

  const fd = new FormData();
  fd.append('file',        fileInput.files[0]);
  fd.append('asset_id',    id);
  fd.append('label',       label);
  fd.append('uploaded_by', CURRENT_USER);

  const res = await fetch(API.attach, { method: 'POST', body: fd });
  const r   = await res.json();

  if (r.success) {
    toast('success', 'Uploaded', fileInput.files[0].name);
    fileInput.value = '';
    loadModalAttachments(id);
  } else {
    toast('error', 'Upload Failed', r.message);
  }
}

async function deleteAttach(attachId, assetId) {
  confirmDialog('Delete Attachment', 'This cannot be undone.', async () => {
    const r = await apiFetch(`${API.attach}?id=${attachId}`, { method: 'DELETE' });
    if (r.success) { toast('success', 'Deleted'); loadModalAttachments(assetId); }
    else toast('error', 'Error', r.message);
  });
}

// ── EXPORT ASSETS ─────────────────────────────────────────────
async function exportAssets() {
  const r = await apiFetch(`${API.assets}?per_page=9999`);
  if (!r.success) return;
  const rows = [
    ['Asset Tag','Serial No.','Category','Make','Model','Status','Assigned To','Dept','Location','Warranty End'],
    ...r.data.items.map(a => [a.asset_tag, a.serial_number, a.category_name, a.make, a.model, a.status, a.assigned_user_name, a.department_name, a.location_full, a.warranty_end])
  ];
  downloadCSV('assets_export.csv', rows);
  toast('success', 'Export Complete', 'Assets exported to CSV.');
}

// ══════════════════════════════════════════════════════════════
//  STOCK ROOM
// ══════════════════════════════════════════════════════════════

async function loadStock(page = 1) {
  state.stockPage = page;
  const search = val('stock-search');
  const cat    = val('stock-filter-cat');
  const status = val('stock-filter-status');

  setEl('stock-table-body', '<tr><td colspan="7" class="table-empty"><p>Loading…</p></td></tr>');

  const qs = `?page=${page}&per_page=15&search=${enc(search)}&category_id=${cat}`;
  const r  = await apiFetch(API.stock + qs);
  if (!r.success) { toast('error', 'Error', r.message); return; }

  const { items, total, total_pages } = r.data;

  // Stock value sum
  let totalValue = 0;
  items.forEach(i => { totalValue += parseFloat(i.unit_cost || 0) * parseFloat(i.total_qty_on_hand || 0); });

  // Refresh stat cards with real data
  const low = await apiFetch(`${API.stock}?action=low_stock`);
  const lowItems = low.data || [];
  const stockouts = lowItems.filter(i => parseFloat(i.quantity_on_hand) <= 0);

  // Get total inventory value from all items (simplified)
  const allR = await apiFetch(`${API.stock}?per_page=9999`);
  let grandTotal = 0;
  (allR.data?.items || []).forEach(i => { grandTotal += parseFloat(i.unit_cost || 0) * parseFloat(i.total_qty_on_hand || 0); });

  setEl('stock-total-value', `₱${grandTotal.toLocaleString('en-PH', {minimumFractionDigits: 2})}`);
  setEl('stock-skus',        allR.data?.total || total);
  setEl('stock-below-rop',   lowItems.length);
  setEl('stock-stockout',    stockouts.length);

  // Filter by status client-side
  let filtered = items;
  if (status === 'below_rop') filtered = items.filter(i => lowItems.some(l => l.id === i.id));
  else if (status === 'stockout') filtered = items.filter(i => parseFloat(i.total_qty_on_hand) <= 0);

  setEl('stock-count-label', `${total} Total Active SKUs`);

  if (!filtered.length) {
    setEl('stock-table-body', emptyRow(7, 'No items found.', 'Try adjusting your filters or add a new item.'));
    renderPagination('stock-pagination', page, total_pages, total, loadStock);
    return;
  }

  setEl('stock-table-body', filtered.map(i => {
    const qty   = parseFloat(i.total_qty_on_hand || 0);
    const isLow = lowItems.some(l => l.id === i.id);
    const isOut = qty <= 0;

    let statusBadges = `<span class="badge badge-instock">In stock</span>`;
    if (isOut) statusBadges = `<span class="badge badge-stockout">Stockout</span>`;
    else if (isLow) statusBadges = `<span class="badge badge-below-rop">Below ROP</span>`;

    // Find rop/min/max from low items
    const lowData = lowItems.find(l => l.id === i.id);

    return `<tr>
      <td class="fw-600">${esc(i.item_code)}</td>
      <td>${esc(i.name)}</td>
      <td class="fw-600">${qty.toFixed(0)}</td>
      <td class="text-muted">${lowData?.min_level ?? '—'}</td>
      <td class="text-muted">${lowData?.max_level ?? '—'}</td>
      <td class="text-muted">${lowData?.reorder_point ?? '—'}</td>
      <td>${statusBadges}</td>
      <td>
        <div style="display:flex;gap:5px;align-items:center">
          <button class="btn-icon" onclick="viewStockItem(${i.id})" title="View">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
          <button class="btn-icon" onclick="editStockItem(${i.id})" title="Edit">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <button class="btn-icon" title="More">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
          </button>
        </div>
      </td>
    </tr>`;
  }).join(''));

  renderPagination('stock-pagination', page, total_pages, total, loadStock);
}

// ── ADD STOCK ITEM MODAL ──────────────────────────────────────
async function openAddStockItem() {
  state.currentStockId = null;
  document.getElementById('modal-stock-title').textContent = 'Add Item';
  document.getElementById('form-stock').reset();
  document.getElementById('si-id').value = '';
  await populateSelect('si-category', 'categories');
  openModal('modal-stock-item');
}

async function editStockItem(id) {
  const r = await apiFetch(`${API.stock}?id=${id}`);
  if (!r.success) return;
  const item = r.data;
  state.currentStockId = id;

  document.getElementById('modal-stock-title').textContent = 'Edit Item';
  await populateSelect('si-category', 'categories');

  setFormVals({
    'si-id':        item.id,
    'si-item_code': item.item_code,
    'si-name':      item.name,
    'si-category':  item.category_id,
    'si-uom':       item.unit_of_measure,
    'si-min':       item.stock_levels?.[0]?.min_level,
    'si-max':       item.stock_levels?.[0]?.max_level,
    'si-rop':       item.stock_levels?.[0]?.reorder_point,
    'si-description': item.description,
  });
  openModal('modal-stock-item');
}

async function saveStockItem() {
  const id = val('si-id');
  const body = {
    item_code:       val('si-item_code'),
    name:            val('si-name'),
    category_id:     val('si-category')     || null,
    unit_of_measure: val('si-uom')          || 'pcs',
    min_level:       val('si-min')          || 0,
    max_level:       val('si-max')          || null,
    reorder_point:   val('si-rop')          || null,
    description:     val('si-description') || null,
  };

  const url    = id ? `${API.stock}?id=${id}` : API.stock;
  const method = id ? 'PUT' : 'POST';
  const r = await apiFetch(url, { method, body: JSON.stringify(body) });

  if (r.success) {
    toast('success', id ? 'Item Updated' : 'Item Added');
    closeModal('modal-stock-item');
    loadStock(state.stockPage);
  } else {
    toast('error', 'Save Failed', r.message);
  }
}

// ── ISSUE STOCK MODAL ─────────────────────────────────────────
async function openIssueStock() {
  document.getElementById('form-issue').reset();
  const allR = await apiFetch(`${API.stock}?per_page=200`);
  const itemSel = document.getElementById('issue-item');
  itemSel.innerHTML = '<option value="">Select Item</option>';
  (allR.data?.items || []).forEach(i => {
    const opt = document.createElement('option');
    opt.value = i.id;
    opt.dataset.qty = i.total_qty_on_hand || 0;
    opt.dataset.uom = i.unit_of_measure || 'pcs';
    opt.textContent = `${i.name} (${i.item_code})`;
    itemSel.appendChild(opt);
  });

  await populateSelect('issue-location', 'locations', { blankLabel: 'Select location…' });
  await populateSelect('issue-department', 'departments', { blankLabel: 'Select department…' });

  document.getElementById('issue-qty-display').textContent = '—';
  document.getElementById('issue-avail-display').textContent = '—';
  document.getElementById('issue-uom').textContent = '';
  document.getElementById('issue-oos-msg').classList.add('hidden');

  openModal('modal-issue-stock');
}

document.addEventListener('change', e => {
  if (e.target.id === 'issue-item') {
    const opt  = e.target.selectedOptions[0];
    const qty  = parseFloat(opt?.dataset?.qty || 0);
    const uom  = opt?.dataset?.uom || '';
    setEl('issue-qty-display', qty);
    setEl('issue-avail-display', qty);
    setEl('issue-uom', uom + '(s)');
    document.getElementById('issue-oos-msg').classList.toggle('hidden', qty > 0);
  }
  if (e.target.id === 'issue-qty-input') {
    const opt    = document.getElementById('issue-item').selectedOptions[0];
    const avail  = parseFloat(opt?.dataset?.qty || 0);
    const req    = parseFloat(e.target.value || 0);
    document.getElementById('issue-oos-msg').classList.toggle('hidden', req <= avail || !req);
  }
});

async function confirmIssueStock() {
  const body = {
    item_id:     val('issue-item')        || null,
    location_id: val('issue-location')   || null,
    quantity:    val('issue-qty-input')   || null,
    reason_code: val('issue-reason')      || 'Normal',
    notes:       val('issue-notes')       || null,
    performed_by: CURRENT_USER,
  };
  if (!body.item_id || !body.location_id || !body.quantity) {
    toast('warning', 'Missing Fields', 'Item, location, and quantity are required.');
    return;
  }
  const r = await apiFetch(`${API.txn}?action=issue`, { method: 'POST', body: JSON.stringify(body) });
  if (r.success) {
    toast('success', 'Stock Issued', `${body.quantity} units issued.`);
    closeModal('modal-issue-stock');
    loadStock(state.stockPage);
  } else {
    toast('error', 'Issue Failed', r.message);
  }
}

// ── RECEIVED STOCK (GRN) ──────────────────────────────────────
async function openReceivedStock() {
  document.getElementById('form-grn').reset();
  const allR = await apiFetch(`${API.stock}?per_page=200`);
  const itemSel = document.getElementById('grn-item');
  itemSel.innerHTML = '<option value="">Select Item</option>';
  (allR.data?.items || []).forEach(i => {
    const opt = document.createElement('option');
    opt.value = i.id;
    opt.textContent = `${i.name} (${i.item_code})`;
    itemSel.appendChild(opt);
  });
  await populateSelect('grn-location', 'locations', { blankLabel: 'Select location…' });
  openModal('modal-grn');
}

async function confirmGRN() {
  const body = {
    item_id:     val('grn-item')      || null,
    location_id: val('grn-location')  || null,
    quantity:    val('grn-qty')       || null,
    notes:       val('grn-notes')     || null,
    performed_by: CURRENT_USER,
  };
  if (!body.item_id || !body.location_id || !body.quantity) {
    toast('warning', 'Missing Fields', 'Item, location, and quantity are required.');
    return;
  }
  const r = await apiFetch(`${API.txn}?action=grn`, { method: 'POST', body: JSON.stringify(body) });
  if (r.success) {
    toast('success', 'Stock Received', `${body.quantity} units added.`);
    closeModal('modal-grn');
    loadStock(state.stockPage);
  } else {
    toast('error', 'GRN Failed', r.message);
  }
}

async function viewStockItem(id) {
  toast('info', 'View Item', 'Stock item detail view — connect to transactions ledger.');
}

// ══════════════════════════════════════════════════════════════
//  CYCLE COUNTS
// ══════════════════════════════════════════════════════════════

async function loadCycles(page = 1) {
  const r = await apiFetch(`${API.cycles}?page=${page}&per_page=15`);
  if (!r.success) return;
  const { items, total, total_pages } = r.data;
  const el = document.getElementById('cycle-table-body');

  if (!items.length) {
    el.innerHTML = emptyRow(6, 'No cycle count plans.', 'Create a plan to start physical inventory.');
    return;
  }
  el.innerHTML = items.map(p => `
    <tr>
      <td class="fw-600">${esc(p.name)}</td>
      <td>${esc(p.location_name || 'All')}</td>
      <td>${p.planned_date}</td>
      <td><span class="badge badge-${p.status === 'Approved' ? 'instock' : p.status === 'In Progress' ? 'inuse' : 'repair'}">${esc(p.status)}</span></td>
      <td>${esc(p.created_by_name || '—')}</td>
      <td>
        ${p.status === 'Draft' ? `<button class="btn btn-secondary btn-xs" onclick="startCycle(${p.id})">Start</button>` : ''}
        ${p.status === 'In Progress' ? `<button class="btn btn-primary btn-xs" onclick="reconcileCycle(${p.id})">Reconcile</button>` : ''}
      </td>
    </tr>`).join('');
  renderPagination('cycle-pagination', page, total_pages, total, loadCycles);
}

async function startCycle(id) {
  const r = await apiFetch(`${API.cycles}?action=start&id=${id}`, { method: 'POST', body: '{}' });
  if (r.success) { toast('success', 'Count Started'); loadCycles(); }
  else toast('error', 'Error', r.message);
}

async function reconcileCycle(id) {
  confirmDialog('Reconcile Count', 'Apply all approved variances to stock levels?', async () => {
    const r = await apiFetch(`${API.cycles}?action=reconcile&id=${id}`, {
      method: 'POST', body: JSON.stringify({ performed_by: CURRENT_USER })
    });
    if (r.success) { toast('success', 'Reconciled', `${r.data.adjusted_entries} entries applied.`); loadCycles(); }
    else toast('error', 'Error', r.message);
  });
}

async function openAddCyclePlan() {
  document.getElementById('form-cycle').reset();
  await populateSelect('cc-location', 'locations', { blankLabel: 'All locations' });
  openModal('modal-cycle');
}

async function saveCyclePlan() {
  const body = {
    name:        val('cc-name'),
    planned_date:val('cc-date'),
    location_id: val('cc-location') || null,
    notes:       val('cc-notes')    || null,
    created_by:  CURRENT_USER,
  };
  const r = await apiFetch(API.cycles, { method: 'POST', body: JSON.stringify(body) });
  if (r.success) { toast('success', 'Plan Created'); closeModal('modal-cycle'); loadCycles(); }
  else toast('error', 'Error', r.message);
}

// ══════════════════════════════════════════════════════════════
//  FILTER DROPDOWNS INIT
// ══════════════════════════════════════════════════════════════

async function initFilters() {
  await Promise.all([
    populateSelect('asset-filter-cat',  'categories',  { blankLabel: 'All' }),
    populateSelect('asset-filter-dept', 'departments', { blankLabel: 'All' }),
    populateSelect('asset-filter-loc',  'locations',   { blankLabel: 'All' }),
    populateSelect('stock-filter-cat',  'categories',  { blankLabel: 'All Categories' }),
  ]);
}

// ══════════════════════════════════════════════════════════════
//  UTILITIES
// ══════════════════════════════════════════════════════════════

function val(id)       { return document.getElementById(id)?.value?.trim() ?? ''; }
function setEl(id, v)  { const e = document.getElementById(id); if (e) e.textContent = v ?? '—'; }
function enc(s)        { return encodeURIComponent(s); }
function esc(s)        {
  if (s == null) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setFormVals(map) {
  Object.entries(map).forEach(([id, v]) => {
    const el = document.getElementById(id);
    if (el && v != null) el.value = v;
  });
}

function statusBadge(status) {
  const cls = {
    'In-Use':       'badge-inuse',
    'In-Stock':     'badge-instock',
    'Under Repair': 'badge-repair',
    'Retired':      'badge-retired',
    'Disposed':     'badge-disposed',
    'Lost':         'badge-lost',
  }[status] || 'badge-disposed';
  return `<span class="badge ${cls}">${esc(status)}</span>`;
}

function emptyRow(cols, title, sub = '') {
  return `<tr><td colspan="${cols}">
    <div class="table-empty">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <p>${esc(title)}</p>
      <span>${esc(sub)}</span>
    </div>
  </td></tr>`;
}

function renderPagination(containerId, page, totalPages, total, fn) {
  const el = document.getElementById(containerId);
  if (!el) return;
  if (totalPages <= 1) { el.innerHTML = `<span class="page-info">${total} records</span>`; return; }

  let html = `<span class="page-info">${total} records</span>`;
  html += `<button class="page-btn" onclick="${fn.name}(${page - 1})" ${page <= 1 ? 'disabled' : ''}>‹</button>`;
  for (let i = 1; i <= Math.min(totalPages, 5); i++) {
    html += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="${fn.name}(${i})">${i}</button>`;
  }
  if (totalPages > 5) html += `<span style="color:var(--text-muted);padding:0 4px">…</span><button class="page-btn ${page === totalPages ? 'active' : ''}" onclick="${fn.name}(${totalPages})">${totalPages}</button>`;
  html += `<button class="page-btn" onclick="${fn.name}(${page + 1})" ${page >= totalPages ? 'disabled' : ''}>›</button>`;
  el.innerHTML = html;
}

function downloadCSV(filename, rows) {
  const content = rows.map(r => r.map(c => `"${(c || '').toString().replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob([content], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
}

// ══════════════════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', async () => {
  // Nav clicks
  document.querySelectorAll('.nav-item[data-page]').forEach(item => {
    item.addEventListener('click', () => navigate(item.dataset.page));
  });

  // Init modal tabs
  ['modal-asset'].forEach(initModalTabs);

  // Init filters
  await initFilters();

  // Load default page
  navigate('dashboard');

  // Global search (basic)
  const searchInput = document.getElementById('global-search');
  if (searchInput) {
    searchInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        const q = searchInput.value.trim();
        if (q) {
          document.getElementById('asset-search').value = q;
          navigate('assets');
          loadAssets(1);
        }
      }
    });
  }
});
