/* ============================================================
   assets.js — Asset Master page logic (Module 1)
   Depends on: app.js, layout.js
   ============================================================ */

'use strict';

ApiClient.base = '/module1/public';

const state = {
  assetPage:      1,
  currentAssetId: null,
};

// ── LOAD ASSETS ───────────────────────────────────────────────
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
  FormHelper.set('a-id', '');
  resetModalTabs('modal-asset');
  await loadAssetSelects();
  document.getElementById('attach-list').innerHTML = '<p style="color:var(--text-3);font-size:13px">Save the asset first to upload documents.</p>';
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
    'a-id': a.id, 'a-asset_tag': a.asset_tag, 'a-serial_number': a.serial_number,
    'a-category_id': a.category_id, 'a-make': a.make, 'a-model': a.model,
    'a-os': a.os, 'a-firmware': a.firmware_version, 'a-status': a.status,
    'a-specs': a.notes, 'a-vendor_id': a.vendor_id, 'a-po_number': a.po_number,
    'a-invoice_number': a.invoice_number, 'a-purchase_cost': a.purchase_cost,
    'a-date_acquired': a.date_acquired, 'a-warranty_start': a.warranty_start,
    'a-warranty_end': a.warranty_end, 'a-sla_tier': a.sla_tier,
    'a-support_contact': a.support_contract_ref, 'a-user_id': a.assigned_user_id,
    'a-department': a.department_id, 'a-location': a.location_id,
    'a-cost_center': a.cost_center, 'a-parent_asset': a.parent_asset_tag,
  });
  loadAttachments(id);
  Modal.open('modal-asset');
}

// ── SAVE ASSET ────────────────────────────────────────────────
async function saveAsset() {
  const id   = FormHelper.val('a-id');
  const body = {
    id: id || undefined,
    asset_tag:            FormHelper.val('a-asset_tag'),
    serial_number:        FormHelper.val('a-serial_number'),
    category_id:          FormHelper.val('a-category_id'),
    make:                 FormHelper.val('a-make'),
    model:                FormHelper.val('a-model'),
    os:                   FormHelper.val('a-os'),
    firmware_version:     FormHelper.val('a-firmware'),
    status:               FormHelper.val('a-status') || 'In-Stock',
    notes:                FormHelper.val('a-specs'),
    vendor_id:            FormHelper.val('a-vendor_id'),
    po_number:            FormHelper.val('a-po_number'),
    invoice_number:       FormHelper.val('a-invoice_number'),
    purchase_cost:        FormHelper.val('a-purchase_cost'),
    date_acquired:        FormHelper.val('a-date_acquired'),
    warranty_start:       FormHelper.val('a-warranty_start'),
    warranty_end:         FormHelper.val('a-warranty_end'),
    sla_tier:             FormHelper.val('a-sla_tier'),
    support_contract_ref: FormHelper.val('a-support_contact'),
    assigned_user_id:     FormHelper.val('a-user_id'),
    department_id:        FormHelper.val('a-department'),
    location_id:          FormHelper.val('a-location'),
    cost_center:          FormHelper.val('a-cost_center'),
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
  const s = FormHelper.setText.bind(FormHelper);
  s('vw-serial', a.serial_number); s('vw-brand', (a.make||'—')+' / '+(a.model||'—'));
  s('vw-category', a.category_name); s('vw-os', a.os); s('vw-firmware', a.firmware_version);
  s('vw-spec', a.notes); s('vw-vendor', a.vendor_name);
  s('vw-po', (a.po_number||'—')+' / '+(a.invoice_number||'—'));
  s('vw-cost', a.purchase_cost ? peso(a.purchase_cost) : '—');
  s('vw-acquired', a.date_acquired);
  s('vw-warranty', a.warranty_start && a.warranty_end ? `${a.warranty_start} → ${a.warranty_end}` : '—');
  s('vw-sla', a.sla_tier); s('vw-assigned', a.assigned_user_name);
  s('vw-dept', a.department_name); s('vw-location', a.location_full || a.location_name);
  s('vw-cc', a.cost_center);
  const log = a.lifecycle_log || [];
  document.getElementById('vw-timeline').innerHTML = log.length
    ? log.map(l => `<div class="timeline-item"><div class="tl-dot"></div><div><div class="tl-label">${esc(l.action_type)}${l.to_status?' → '+l.to_status:''}${l.reason?' — '+l.reason:''}</div><div class="tl-date">${l.performed_at} · ${esc(l.performed_by_name||'—')}</div></div></div>`).join('')
    : '<p class="text-muted" style="font-size:13px">No history yet.</p>';
  Modal.open('modal-view-asset');
}

function openEditFromView()     { Modal.close('modal-view-asset'); editAsset(state.currentAssetId); }
function openTransferFromView() { Modal.close('modal-view-asset'); openTransfer(state.currentAssetId); }

// ── TRANSFER ──────────────────────────────────────────────────
async function openTransfer(id) {
  state.currentAssetId = id;
  const r = await ApiClient.get('/asset/view' + ApiClient.qs({ id }));
  if (!r.success) return;
  const a = r.data;
  document.getElementById('tf-asset-tag').textContent = a.asset_tag;
  const sb = document.getElementById('tf-status-badge');
  sb.className = 'badge ' + ({'In-Use':'b-in-use','In-Stock':'b-in-stock','Under Repair':'b-under-repair','Retired':'b-retired'}[a.status]||'b-disposed');
  sb.textContent = a.status;
  document.getElementById('tf-cat-badge').textContent = a.category_name || '—';
  FormHelper.set('tf-current', a.assigned_user_name || 'Unassigned');
  FormHelper.set('tf-custodian', ''); FormHelper.set('tf-reason', '');
  document.getElementById('tf-signoff').checked = false;
  await Promise.all([
    Lookup.populate('tf-department', 'departments', { blank: 'Select department…' }),
    Lookup.populate('tf-location',   'locations',   { blank: 'Select location…', labelFn: i => (i.site_name ? i.site_name+' › ' : '')+i.name }),
  ]);
  Modal.open('modal-transfer');
}

async function confirmTransfer() {
  if (!document.getElementById('tf-signoff').checked) {
    Toast.show('warning', 'Sign-off Required', 'Please confirm the new custodian has acknowledged.');
    return;
  }
  const r = await ApiClient.post('/asset/transfer', {
    asset_id:         state.currentAssetId,
    to_department_id: FormHelper.val('tf-department') || null,
    to_location_id:   FormHelper.val('tf-location')   || null,
    reason:           FormHelper.val('tf-reason'),
  });
  if (r.success) { Toast.show('success', 'Transfer Complete'); Modal.close('modal-transfer'); loadAssets(state.assetPage); }
  else Toast.show('error', 'Transfer Failed', r.message);
}

// ── EXPORT ────────────────────────────────────────────────────
async function exportAssets() {
  const r = await ApiClient.get('/asset/list' + ApiClient.qs({ per_page: 9999 }));
  if (!r.success) return;
  const rows = [
    ['Asset Tag','Serial No.','Category','Make','Model','Status','Assigned To','Dept','Location','Warranty End'],
    ...r.data.items.map(a => [a.asset_tag,a.serial_number,a.category_name,a.make,a.model,a.status,a.assigned_user_name,a.department_name,a.location_full,a.warranty_end])
  ];
  const csv  = rows.map(r => r.map(c => `"${(c||'').toString().replace(/"/g,'""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type:'text/csv' });
  const a    = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'assets_export.csv';
  a.click();
  Toast.show('success','Exported','assets_export.csv downloaded.');
}

// ── ATTACHMENTS ───────────────────────────────────────────────
async function loadAttachments(assetId) {
  const r  = await ApiClient.get('/attachment/list' + ApiClient.qs({ asset_id: assetId }));
  const el = document.getElementById('attach-list');
  if (!r.success || !r.data?.length) { el.innerHTML = '<p class="text-muted" style="font-size:13px">No attachments yet.</p>'; return; }
  el.innerHTML = r.data.map(a => `
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
      <span style="font-size:18px">${fileIcon(a.file_type)}</span>
      <div style="flex:1;min-width:0">
        <a href="/attachment/download?id=${a.id}" target="_blank" style="font-size:13px;color:var(--teal);text-decoration:none;font-weight:600">${esc(a.file_name)}</a>
        <div style="font-size:11px;color:var(--text-3)">${esc(a.label)} · ${esc(a.file_size_kb||'')}</div>
      </div>
      <button class="icon-btn" onclick="deleteAttach(${a.id})" title="Delete">✕</button>
    </div>`).join('');
}

async function uploadAttachment() {
  const id = FormHelper.val('a-id') || state.currentAssetId;
  if (!id) { Toast.show('warning','Save First','Save the asset before uploading files.'); return; }
  const fi = document.getElementById('attach-file');
  if (!fi?.files?.length) { Toast.show('warning','No File','Please select a file.'); return; }
  const fd = new FormData();
  fd.append('file', fi.files[0]); fd.append('asset_id', id);
  fd.append('label', FormHelper.val('attach-label')); fd.append('uploaded_by', 1);
  const r = await ApiClient.upload('/attachment/upload', fd);
  if (r.success) { Toast.show('success','Uploaded',fi.files[0].name); fi.value=''; loadAttachments(id); }
  else Toast.show('error','Upload Failed',r.message);
}

async function deleteAttach(attachId) {
  Modal.confirm('Delete Attachment','This cannot be undone.', async () => {
    const r = await ApiClient.post('/attachment/delete', { id: attachId });
    if (r.success) { Toast.show('success','Deleted'); loadAttachments(state.currentAssetId); }
    else Toast.show('error','Error',r.message);
  });
}

// ── HELPERS ───────────────────────────────────────────────────
function resetModalTabs(modalId) {
  const m = document.getElementById(modalId);
  m?.querySelectorAll('.modal-tab').forEach((t,i)  => t.classList.toggle('active', i===0));
  m?.querySelectorAll('.tab-panel').forEach((p,i)  => p.classList.toggle('hidden', i!==0));
}

function clearAssetFilters() {
  ['a-search','a-filter-status','a-filter-cat','a-filter-dept','a-filter-loc'].forEach(id => FormHelper.set(id,''));
  loadAssets(1);
}

// ── INIT ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  renderSidebar();
  initLayout('assets', 'Asset Master');
  Modal.initTabs('modal-asset');

  document.getElementById('global-search')?.addEventListener('keydown', e => {
    if (e.key==='Enter') { FormHelper.set('a-search', e.target.value.trim()); loadAssets(1); }
  });

  document.getElementById('cb-all-assets')?.addEventListener('change', function() {
    document.querySelectorAll('#a-tbody .row-cb').forEach(cb => cb.checked = this.checked);
  });

  await Promise.all([
    Lookup.populate('a-filter-cat',  'categories',  { blank: 'All' }),
    Lookup.populate('a-filter-dept', 'departments', { blank: 'All' }),
    Lookup.populate('a-filter-loc',  'locations',   { blank: 'All', labelFn: i => (i.site_name ? i.site_name+' › ' : '')+i.name }),
  ]);

  loadAssets(1);
});
