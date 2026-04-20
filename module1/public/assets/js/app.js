/* ============================================================
   app.js — Reusable JS utilities for ALL modules
   - ApiClient   : typed fetch wrapper
   - TableRenderer: builds paginated tables
   - FormHelper  : populate/read forms
   - Toast       : notifications
   - Modal       : open/close
   ============================================================ */

'use strict';

// ══════════════════════════════════════════════════════════════
//  API CLIENT — reusable by all modules
// ══════════════════════════════════════════════════════════════
const ApiClient = {
  /** Base path — all routes go through public/index.php */
  base: '/module1/public',

  async _fetch(url, opts = {}) {
    try {
      const res = await fetch(this.base + url, {
        headers: { 'Content-Type': 'application/json' },
        ...opts,
      });
      const data = await res.json();
      return data;
    } catch (e) {
      Toast.show('error', 'Network Error', 'Could not reach server.');
      return { success: false, message: 'Network error' };
    }
  },

  get(url)               { return this._fetch(url); },
  post(url, body)        { return this._fetch(url, { method: 'POST', body: JSON.stringify(body) }); },

  /** Multipart (file upload) — no JSON header */
  async upload(url, formData) {
    try {
      const res  = await fetch(this.base + url, { method: 'POST', body: formData });
      return await res.json();
    } catch (e) {
      return { success: false, message: 'Upload failed.' };
    }
  },

  /** Build query string from object */
  qs(params) {
    const p = Object.entries(params).filter(([,v]) => v !== '' && v != null);
    return p.length ? '?' + new URLSearchParams(p).toString() : '';
  },
};

// ══════════════════════════════════════════════════════════════
//  TOAST NOTIFICATIONS — reusable
// ══════════════════════════════════════════════════════════════
const Toast = {
  icons: {
    success: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`,
    error:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>`,
    warning: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>`,
    info:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4m0-4h.01"/></svg>`,
  },

  show(type, title, msg = '', ms = 3500) {
    const el = document.createElement('div');
    el.className = `toast t-${type}`;
    el.innerHTML = `${this.icons[type]}<div class="toast-body"><div class="toast-title">${esc(title)}</div>${msg ? `<div class="toast-msg">${esc(msg)}</div>` : ''}</div><button class="toast-x" onclick="this.closest('.toast').remove()">✕</button>`;
    document.getElementById('toast-wrap').appendChild(el);
    setTimeout(() => el.remove(), ms);
  },
};

// ══════════════════════════════════════════════════════════════
//  MODAL — reusable
// ══════════════════════════════════════════════════════════════
const Modal = {
  open(id)  { document.getElementById(id)?.classList.add('open'); },
  close(id) { document.getElementById(id)?.classList.remove('open'); },
  closeAll(){ document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open')); },

  initTabs(modalId) {
    document.querySelectorAll(`#${modalId} .modal-tab`).forEach(tab => {
      tab.addEventListener('click', () => {
        const modal  = document.getElementById(modalId);
        const target = tab.dataset.tab;
        modal.querySelectorAll('.modal-tab').forEach(t => t.classList.toggle('active', t === tab));
        modal.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== target));
      });
    });
  },

  confirm(title, msg, onYes) {
    document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-msg').textContent   = msg;
    document.getElementById('confirm-ok').onclick = () => { this.close('modal-confirm'); onYes(); };
    this.open('modal-confirm');
  },
};

// Close on overlay click / ESC
document.addEventListener('click',   e => { if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') Modal.closeAll(); });

// ══════════════════════════════════════════════════════════════
//  TABLE RENDERER — reusable
// ══════════════════════════════════════════════════════════════
const TableRenderer = {
  /**
   * Render rows into a <tbody>.
   * @param {string} tbodyId
   * @param {Array}  items
   * @param {Function} rowFn  — (item) => '<tr>...</tr>'
   * @param {number} colCount — for empty state colspan
   */
  render(tbodyId, items, rowFn, colCount = 8) {
    const el = document.getElementById(tbodyId);
    if (!el) return;
    if (!items?.length) {
      el.innerHTML = `<tr><td colspan="${colCount}"><div class="empty-state">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <p>No records found</p><span>Try adjusting your filters or add new data.</span>
      </div></td></tr>`;
      return;
    }
    el.innerHTML = items.map(rowFn).join('');
  },

  /**
   * Render pagination controls.
   * @param {string}   containerId
   * @param {object}   meta  — { page, total, total_pages }
   * @param {Function} goFn  — (page) => void
   */
  pagination(containerId, meta, goFn) {
    const el = document.getElementById(containerId);
    if (!el) return;
    const { page, total, total_pages } = meta;

    if (total_pages <= 1) {
      el.innerHTML = `<span class="pg-info">${total} record${total !== 1 ? 's' : ''}</span>`;
      return;
    }

    let html = `<span class="pg-info">${total} records</span>`;
    html += `<button class="pg-btn" onclick="(${goFn.toString()})(${page - 1})" ${page <= 1 ? 'disabled' : ''}>‹</button>`;
    const start = Math.max(1, page - 2), end = Math.min(total_pages, start + 4);
    for (let i = start; i <= end; i++) {
      html += `<button class="pg-btn ${i === page ? 'active' : ''}" onclick="(${goFn.toString()})(${i})">${i}</button>`;
    }
    if (end < total_pages) html += `<span style="color:var(--text-3);padding:0 4px">…</span><button class="pg-btn" onclick="(${goFn.toString()})(${total_pages})">${total_pages}</button>`;
    html += `<button class="pg-btn" onclick="(${goFn.toString()})(${page + 1})" ${page >= total_pages ? 'disabled' : ''}>›</button>`;
    el.innerHTML = html;
  },
};

// ══════════════════════════════════════════════════════════════
//  FORM HELPER — reusable
// ══════════════════════════════════════════════════════════════
const FormHelper = {
  /** Read all named inputs from a form into an object */
  read(formId) {
    const form = document.getElementById(formId);
    if (!form) return {};
    const obj = {};
    form.querySelectorAll('[id]').forEach(el => {
      const key = el.id.replace(/^[a-z]+-/, ''); // strip prefix like 'a-', 'si-'
      if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
        obj[key] = el.value?.trim() || null;
      }
    });
    return obj;
  },

  /** Populate form fields from a data object */
  populate(map) {
    Object.entries(map).forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (el && val != null) el.value = val;
    });
  },

  /** Reset a form */
  reset(formId) {
    document.getElementById(formId)?.reset();
  },

  /** Set text content */
  setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val ?? '—';
  },

  /** Get input value */
  val(id) { return document.getElementById(id)?.value?.trim() ?? ''; },

  /** Set input value */
  set(id, v) { const e = document.getElementById(id); if (e) e.value = v ?? ''; },
};

// ══════════════════════════════════════════════════════════════
//  LOOKUP HELPER — populate <select> from API
// ══════════════════════════════════════════════════════════════
const Lookup = {
  _cache: {},

  async get(resource, extra = '') {
    const key = resource + extra;
    if (this._cache[key]) return this._cache[key];
    const r = await ApiClient.get(`/lookup/list?resource=${resource}${extra}`);
    if (r.success) this._cache[key] = r.data;
    return r.data || [];
  },

  clearCache() { this._cache = {}; },

  async populate(selectId, resource, { valueField = 'id', labelFn = null, blank = 'Select…', extra = '' } = {}) {
    const el = document.getElementById(selectId);
    if (!el) return;
    const cur   = el.value;
    const items = await this.get(resource, extra);
    el.innerHTML = `<option value="">${blank}</option>`;
    items.forEach(i => {
      const opt   = document.createElement('option');
      opt.value   = i[valueField];
      opt.textContent = labelFn ? labelFn(i) : (i.name || i.full_name || '');
      el.appendChild(opt);
    });
    if (cur) el.value = cur;
  },
};

// ══════════════════════════════════════════════════════════════
//  UTILITY FUNCTIONS — reusable
// ══════════════════════════════════════════════════════════════

/** HTML-escape a string */
function esc(s) {
  if (s == null) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/** Format currency (PHP Peso) */
function peso(n) {
  return '₱' + parseFloat(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

/** Status → CSS badge class */
function statusBadge(status) {
  const map = {
    'In-Use':       'b-in-use',
    'In-Stock':     'b-in-stock',
    'Under Repair': 'b-under-repair',
    'Retired':      'b-retired',
    'Disposed':     'b-disposed',
    'Lost':         'b-lost',
    'Active':       'b-active',
  };
  return `<span class="badge ${map[status] || 'b-disposed'}">${esc(status)}</span>`;
}

/** Is warranty expiring within 30 days? */
function warrantyWarning(dateStr) {
  if (!dateStr) return false;
  return new Date(dateStr) < new Date(Date.now() + 30 * 86400000);
}

/** File MIME → emoji icon */
function fileIcon(mime) {
  if (mime?.includes('pdf'))    return '📄';
  if (mime?.includes('image'))  return '🖼️';
  if (mime?.includes('word'))   return '📝';
  if (mime?.includes('sheet') || mime?.includes('excel')) return '📊';
  return '📎';
}
