/* ============================================================
   layout.js — Shared sidebar for ALL modules
   Include this in every module HTML after app.js
   ============================================================ */

'use strict';

/**
 * Call this inside each page's DOMContentLoaded.
 * @param {string} activeModule - 'dashboard'|'assets'|'stock'|'module2'|etc.
 * @param {string} pageTitle    - Text shown in the topbar
 */
function initLayout(activeModule, pageTitle) {
  const titleEl = document.getElementById('tb-title');
  if (titleEl) titleEl.textContent = pageTitle;

  document.querySelectorAll('.nav-item[data-module]').forEach(n => {
    n.classList.toggle('active', n.dataset.module === activeModule);
  });

  document.querySelector('.tb-hamburger')?.addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
  });

  const dateEl = document.getElementById('today-date');
  if (dateEl) {
    dateEl.textContent = new Date().toLocaleDateString('en-US', {
      month: 'long', day: 'numeric', year: 'numeric'
    });
  }
}

/**
 * Renders the full sidebar HTML into #sidebar.
 */
function renderSidebar() {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  sidebar.innerHTML = `
    <div class="sb-logo">
      <div class="tag">IT&amp;DS</div>
      <div class="title">Asset &amp; Inventory</div>
      <div class="sub">Preventive Maintenance Tracker</div>
    </div>

    <div class="sb-section">MAIN</div>
    <a class="nav-item" data-module="dashboard" href="module1.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>
    <a class="nav-item" data-module="assets" href="assets.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
      Asset Master
    </a>
    <a class="nav-item" data-module="stock" href="stock.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 8h14M5 12h14M5 16h6"/><rect x="3" y="4" width="18" height="16" rx="2"/></svg>
      Stock Room
    </a>
    <a class="nav-item" data-module="module2" href="module2.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
      Service Planner
    </a>

    <div class="sb-section">OPERATIONS</div>
    <a class="nav-item" data-module="module3" href="module3.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
      Operations
    </a>
    <a class="nav-item" data-module="module4" href="module4.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-1.35 2.7A1 1 0 006.54 17H17M17 17a2 2 0 100 4 2 2 0 000-4zm-10 0a2 2 0 100 4 2 2 0 000-4z"/></svg>
      Supply Chain
    </a>
    <a class="nav-item" data-module="module5a" href="module5a.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
      Incident Logs
    </a>
    <a class="nav-item" data-module="module5b" href="module5b.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
      Asset Health
    </a>

    <div class="sb-section">ADMINISTRATION</div>
    <a class="nav-item" data-module="module6" href="module6.html">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      System Admin
    </a>

    <div class="sb-user">
      <div class="sb-avatar">JD</div>
      <div class="sb-user-info">
        <div class="sb-user-name">Juan Dela Cruz</div>
        <div class="sb-user-role">IT Technician</div>
      </div>
      <button class="sb-settings">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
      </button>
    </div>
  `;
}