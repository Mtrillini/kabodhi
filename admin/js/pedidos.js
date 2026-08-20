// ============================================================
// KABODHI Admin — pedidos.js
// ============================================================

let allPedidos    = [];
let openDetailId  = null;

// ---- Fetch ----
async function fetchPedidos(estado = '') {
  try {
    showTableLoading();
    const url  = API_URL + '/pedidos' + (estado ? '?estado=' + estado : '');
    const res  = await fetch(url, { credentials: 'include' });
    const json = await res.json();

    if (!json.success) throw new Error(json.message || 'Error al cargar pedidos.');

    allPedidos = json.data || [];
    renderTabla(allPedidos);
  } catch (err) {
    showToast(err.message, 'error');
    document.getElementById('pedidos-tbody').innerHTML =
      `<tr><td colspan="7" style="text-align:center;color:var(--taupe);padding:2rem;">${err.message}</td></tr>`;
  }
}

function showTableLoading() {
  const tbody = document.getElementById('pedidos-tbody');
  if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="loading">Cargando...</td></tr>`;
}

// ---- Render table ----
function renderTabla(pedidos) {
  const tbody = document.getElementById('pedidos-tbody');
  if (!tbody) return;

  if (!pedidos.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--taupe);padding:2rem;">Sin pedidos encontrados.</td></tr>`;
    return;
  }

  tbody.innerHTML = pedidos.map(p => `
    <tr
      class="pedido-row"
      data-id="${p.id}"
      style="cursor:pointer;"
      onclick="toggleDetalle(${p.id})"
    >
      <td><strong>#${p.id}</strong></td>
      <td>${escHtml(p.cliente_nombre)}</td>
      <td style="color:var(--taupe);">${escHtml(p.cliente_email)}</td>
      <td>${formatMoney(p.total)}</td>
      <td><span class="badge badge--${p.estado}">${capitalize(p.estado)}</span></td>
      <td style="color:var(--taupe);">${formatDate(p.created_at)}</td>
      <td onclick="event.stopPropagation();">
        <select
          class="filter-select"
          style="font-size:0.68rem;padding:0.3rem 0.5rem;"
          onchange="actualizarEstado(${p.id}, this.value)"
        >
          <option value="">Cambiar estado</option>
          <option value="pendiente"  ${p.estado === 'pendiente'  ? 'selected' : ''}>Pendiente</option>
          <option value="aprobado"   ${p.estado === 'aprobado'   ? 'selected' : ''}>Aprobado</option>
          <option value="rechazado"  ${p.estado === 'rechazado'  ? 'selected' : ''}>Rechazado</option>
          <option value="cancelado"  ${p.estado === 'cancelado'  ? 'selected' : ''}>Cancelado</option>
        </select>
      </td>
    </tr>
    <tr id="detalle-${p.id}" class="row-detail">
      <td colspan="7">
        <div style="font-size:0.7rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:var(--taupe);margin-bottom:0.75rem;">
          Detalle del pedido
        </div>
        <div class="detail-items" id="items-${p.id}">
          <div style="color:var(--taupe);font-size:0.78rem;">Cargando items...</div>
        </div>
        <div style="margin-top:1rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
          ${p.mp_payment_id ? `<div style="font-size:0.72rem;color:var(--taupe);">MP Payment: ${p.mp_payment_id}</div>` : ''}
          ${p.cliente_telefono ? `<div style="font-size:0.72rem;color:var(--taupe);">Tel: ${escHtml(p.cliente_telefono)}</div>` : ''}
          ${p.cliente_direccion ? `<div style="font-size:0.72rem;color:var(--taupe);">Dir: ${escHtml(p.cliente_direccion)}</div>` : ''}
        </div>
      </td>
    </tr>
  `).join('');
}

// ---- Toggle detail row ----
async function toggleDetalle(id) {
  const row = document.getElementById('detalle-' + id);
  if (!row) return;

  if (openDetailId === id) {
    row.classList.remove('open');
    openDetailId = null;
    return;
  }

  // Close previous
  if (openDetailId !== null) {
    const prev = document.getElementById('detalle-' + openDetailId);
    if (prev) prev.classList.remove('open');
  }

  row.classList.add('open');
  openDetailId = id;

  // Load detail from API
  try {
    const res  = await fetch(API_URL + '/pedidos/' + id, { credentials: 'include' });
    const json = await res.json();

    if (!json.success) throw new Error(json.message);

    renderDetalle(id, json.data);
  } catch (err) {
    const itemsEl = document.getElementById('items-' + id);
    if (itemsEl) itemsEl.innerHTML = `<div style="color:#c07b7b;font-size:0.78rem;">${err.message}</div>`;
  }
}

window.toggleDetalle = toggleDetalle;

// ---- Render detail items ----
function renderDetalle(id, pedido) {
  const itemsEl = document.getElementById('items-' + id);
  if (!itemsEl) return;

  if (!pedido.items || !pedido.items.length) {
    itemsEl.innerHTML = `<div style="color:var(--taupe);font-size:0.78rem;">Sin items.</div>`;
    return;
  }

  itemsEl.innerHTML = pedido.items.map(item => `
    <div class="detail-item">
      <span>
        ${escHtml(item.producto_nombre || 'Producto #' + item.producto_id)}
        <span style="color:var(--taupe);"> × ${item.cantidad}</span>
      </span>
      <span>${formatMoney(item.precio_unitario * item.cantidad)}</span>
    </div>
  `).join('') + `
    <div class="detail-item" style="font-weight:600;margin-top:0.5rem;">
      <span>Total</span>
      <span>${formatMoney(pedido.total)}</span>
    </div>
  `;
}

// ---- Update status ----
async function actualizarEstado(id, estado) {
  if (!estado) return;

  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/estado', {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ estado }),
    });
    const json = await res.json();

    if (!json.success) throw new Error(json.message || 'Error al actualizar estado.');

    showToast(`Pedido #${id}: estado actualizado a "${estado}".`, 'success');

    // Update local data and re-render
    const idx = allPedidos.findIndex(p => p.id === id);
    if (idx >= 0) allPedidos[idx].estado = estado;

    // Re-render badge in the row
    const badge = document.querySelector(`.pedido-row[data-id="${id}"] .badge`);
    if (badge) {
      badge.className = `badge badge--${estado}`;
      badge.textContent = capitalize(estado);
    }

  } catch (err) {
    showToast(err.message, 'error');
    // Re-fetch to restore correct state
    fetchPedidos();
  }
}

window.actualizarEstado = actualizarEstado;

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  const authenticated = await checkAuth();
  if (!authenticated) return;

  fetchPedidos();

  // Estado filter
  const filterSelect = document.getElementById('filter-estado');
  if (filterSelect) {
    filterSelect.addEventListener('change', () => {
      fetchPedidos(filterSelect.value);
    });
  }
});
