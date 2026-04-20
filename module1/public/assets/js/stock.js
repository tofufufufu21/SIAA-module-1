/* ============================================================
   stock.js — Stock Room page logic (Module 1)
   Depends on: app.js, layout.js
   ============================================================ */

'use strict';

ApiClient.base = '/module1/public';

const state = {
  stockPage:       1,
  currentStockId:  null,
};

// ── LOAD STOCK ────────────────────────────────────────────────
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

  const allR = await ApiClient.get('/stock/list' + ApiClient.qs({ per_page: 9999 }));
  let totalVal = 0;
  (allR.data?.items || []).forEach(i => { totalVal += parseFloat(i.unit_cost || 0) * parseFloat(i.total_qty_on_hand || 0); });

  FormHelper.setText('s-total-value', peso(totalVal));
  FormHelper.setText('s-skus',        allR.data?.total ?? '—');
  FormHelper.setText('s-below-rop',   rLow.data?.length ?? 0);
  FormHelper.setText('s-stockout',    stockouts.length);

  const { items } = rItems.data;
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
      </div>
    </td>
  </tr>`;
}

// ── ADD STOCK ITEM ─────────────────────────────────────────────
async function openAddStockItem() {
  state.currentStockId = null;
  document.getElementById('modal-stock-title').textContent = 'Add Item';
  FormHelper.reset('form-stock');
  FormHelper.set('si-id', '');
  await Lookup.populate('si-category', 'categories');
  Modal.open('modal-stock-item');
}

async function editStockItem(id) {
  const r2   = await ApiClient.get('/stock/list' + ApiClient.qs({ per_page: 999 }));
  const item = r2.data?.items?.find(i => i.id === id);
  if (!item) return;
  state.currentStockId = id;
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
  Toast.show('info', 'View Item', 'Full item detail — connects to stock ledger.');
}

// ── ISSUE STOCK ───────────────────────────────────────────────
async function openIssueStock() {
  FormHelper.reset('form-issue');
  const allR = await ApiClient.get('/stock/list' + ApiClient.qs({ per_page: 200 }));
  const sel  = document.getElementById('issue-item');
  sel.innerHTML = '<option value="">Select Item</option>';
  (allR.data?.items || []).forEach(i => {
    const o = document.createElement('option');
    o.value = i.id; o.dataset.qty = i.total_qty_on_hand || 0; o.dataset.uom = i.unit_of_measure || 'pcs';
    o.textContent = `${i.name} (${i.item_code})`; sel.appendChild(o);
  });
  await Promise.all([
    Lookup.populate('issue-location',   'locations',   { blank: 'Select location…',   labelFn: i => (i.site_name ? i.site_name+' › ' : '')+i.name }),
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
  await Lookup.populate('grn-location', 'locations', { blank: 'Select location…', labelFn: i => (i.site_name ? i.site_name+' › ' : '')+i.name });
  Modal.open('modal-grn');
}

async function confirmGRN() {
  const body = { item_id: FormHelper.val('grn-item'), location_id: FormHelper.val('grn-location'), quantity: FormHelper.val('grn-qty'), notes: FormHelper.val('grn-notes') };
  if (!body.item_id || !body.location_id || !body.quantity) { Toast.show('warning', 'Missing Fields'); return; }
  const r = await ApiClient.post('/stock/receive', body);
  if (r.success) { Toast.show('success', 'Stock Received'); Modal.close('modal-grn'); loadStock(state.stockPage); }
  else Toast.show('error', 'GRN Failed', r.message);
}

// ── INIT ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  renderSidebar();
  initLayout('stock', 'Stock Room');
  await Lookup.populate('s-filter-cat', 'categories', { blank: 'All Categories' });
  loadStock(1);
});
