// ============================================================
// KABODHI Admin — envios-historial.js
// Historial de envios: que se cotizo, que se cobro y en que estado esta
// cada uno. El detalle y las acciones viven en Pedidos.
// ============================================================

let envios  = [];
let ESTADOS = {};

const ENVIO_BADGE = {
  pendiente: 'pendiente', preparando: 'pendiente', enviado: 'enviado', en_traslado: 'enviado',
  en_sucursal: 'enviado', entregado: 'entregado', rechazado: 'rechazado', devuelto: 'rechazado', cancelado: 'cancelado',
};

const PROVEEDOR_LABEL = { correo: 'Correo Argentino', tabla: 'Tarifa de la tienda', sin_costo: 'Sin costo' };

function filtros() {
  return {
    estado:    document.getElementById('filter-estado')?.value    || '',
    proveedor: document.getElementById('filter-proveedor')?.value || '',
    desde:     document.getElementById('filter-desde')?.value     || '',
    hasta:     document.getElementById('filter-hasta')?.value     || '',
    q:         (document.getElementById('search-envios')?.value   || '').trim(),
  };
}

async function fetchEnvios() {
  const tbody = document.getElementById('envios-tbody');
  tbody.innerHTML = `<tr><td colspan="9" class="loading">Cargando...</td></tr>`;

  try {
    const qs   = new URLSearchParams(Object.entries(filtros()).filter(([, v]) => v !== '')).toString();
    const res  = await fetch(API_URL + '/envios/historial' + (qs ? '?' + qs : ''), { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al cargar los envíos.');

    envios = json.data || [];
    if (json.estados) {
      ESTADOS = json.estados;
      llenarEstados();
    }
    renderResumen(json.resumen || {});
    renderTabla();
  } catch (err) {
    showToast(err.message, 'error');
    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:var(--taupe);padding:2rem;">${escHtml(err.message)}</td></tr>`;
  }
}

function llenarEstados() {
  const sel = document.getElementById('filter-estado');
  if (!sel || sel.options.length > 1) return;
  const actual = sel.value;
  sel.innerHTML = '<option value="">Todos los estados</option>' +
    Object.entries(ESTADOS).map(([k, v]) => `<option value="${k}">${escHtml(v)}</option>`).join('');
  sel.value = actual;
}

function renderResumen(r) {
  const el = document.getElementById('envios-resumen');
  if (!el) return;
  const cotizado = parseFloat(r.cotizado || 0);
  const cobrado  = parseFloat(r.cobrado  || 0);
  el.innerHTML = `
    <div class="stat-card">
      <div class="stat-card__label">Envíos</div>
      <div class="stat-card__value">${r.envios || 0}</div>
      <div class="stat-card__sub">${r.por_despachar || 0} por despachar · ${r.en_curso || 0} en curso</div>
    </div>
    <div class="stat-card">
      <div class="stat-card__label">Entregados</div>
      <div class="stat-card__value">${r.entregados || 0}</div>
      <div class="stat-card__sub">sobre el total histórico</div>
    </div>
    <div class="stat-card">
      <div class="stat-card__label">Cobrado por envíos</div>
      <div class="stat-card__value" style="font-size:1.4rem;">${formatMoney(cobrado)}</div>
      <div class="stat-card__sub">${r.bonificados || 0} bonificado(s)</div>
    </div>
    <div class="stat-card">
      <div class="stat-card__label">Costo cotizado</div>
      <div class="stat-card__value" style="font-size:1.4rem;">${formatMoney(cotizado)}</div>
      <div class="stat-card__sub">diferencia absorbida: ${formatMoney(Math.max(0, cotizado - cobrado))}</div>
    </div>
  `;
}

function renderTabla() {
  const tbody = document.getElementById('envios-tbody');
  const info  = document.getElementById('resumen-filtros');

  if (!envios.length) {
    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:var(--taupe);padding:2rem;">Sin envíos para esos filtros.</td></tr>`;
    if (info) info.textContent = '';
    return;
  }

  const cobrado  = envios.reduce((s, e) => s + parseFloat(e.costo_cobrado  || 0), 0);
  const cotizado = envios.reduce((s, e) => s + parseFloat(e.costo_cotizado || 0), 0);
  if (info) {
    info.innerHTML = `<strong>${envios.length}</strong> envío(s) &nbsp;·&nbsp; cotizado <strong>${formatMoney(cotizado)}</strong> &nbsp;·&nbsp; cobrado <strong>${formatMoney(cobrado)}</strong>`;
  }

  tbody.innerHTML = envios.map(e => `
    <tr>
      <td>
        <a href="pedidos.html#pedido-${e.pedido_id}" style="color:var(--negro);font-weight:600;">#${e.pedido_id}</a>
        <div><span class="badge badge--${escAttr(e.pedido_estado)}" style="font-size:0.58rem;">${escHtml(e.pedido_estado)}</span></div>
      </td>
      <td>
        ${escHtml(e.cliente_nombre)}
        <div style="color:var(--taupe);font-size:0.68rem;">${escHtml(e.cliente_email)}</div>
      </td>
      <td>
        ${escHtml(PROVEEDOR_LABEL[e.proveedor] || e.proveedor)}
        <div style="color:var(--taupe);font-size:0.68rem;">
          ${e.tipo_entrega === 'sucursal' ? 'Sucursal' : 'Domicilio'}${e.producto ? ' · ' + escHtml(e.producto === 'EP' ? 'Expreso' : e.producto === 'CP' ? 'Clásico' : e.producto) : ''}
          ${e.importado_at ? ' · MiCorreo ✓' : ''}
        </div>
      </td>
      <td>
        CP ${escHtml(e.cp_destino)}
        <div style="color:var(--taupe);font-size:0.68rem;">${escHtml(e.tipo_entrega === 'sucursal' ? (e.sucursal_nombre || e.sucursal_codigo || '') : [e.dest_ciudad, e.dest_provincia].filter(Boolean).join(', '))}</div>
      </td>
      <td>${formatMoney(e.costo_cotizado)}</td>
      <td>
        <strong>${formatMoney(e.costo_cobrado)}</strong>
        ${e.bonificado == 1 ? '<div><span class="badge badge--aprobado" style="font-size:0.58rem;">bonificado</span></div>' : ''}
      </td>
      <td>
        <span class="badge badge--${ENVIO_BADGE[e.estado] || 'pendiente'}" style="font-size:0.62rem;">${escHtml(e.estado_label || e.estado)}</span>
        <div style="color:var(--taupe);font-size:0.66rem;">${formatDate(e.estado_at)}</div>
      </td>
      <td style="font-family:monospace;font-size:0.72rem;">
        ${e.tracking_codigo
          ? (e.tracking_url_publica
              ? `<a href="${escAttr(e.tracking_url_publica)}" target="_blank" style="color:var(--negro);">${escHtml(e.tracking_codigo)}</a>`
              : escHtml(e.tracking_codigo))
          : '<span style="color:var(--taupe);">—</span>'}
      </td>
      <td style="color:var(--taupe);white-space:nowrap;">${formatDate(e.pedido_created_at)}</td>
    </tr>
  `).join('');
}

function exportarCSV() {
  if (!envios.length) { showToast('No hay envíos para exportar.', 'info'); return; }

  const cab = ['pedido', 'fecha', 'cliente', 'email', 'servicio', 'producto', 'entrega', 'cp_destino', 'ciudad', 'provincia',
               'sucursal', 'cotizado', 'cobrado', 'bonificado', 'estado_envio', 'fecha_estado', 'tracking', 'orden_micorreo', 'estado_pedido'];
  const filas = envios.map(e => [
    e.pedido_id, e.pedido_created_at, e.cliente_nombre, e.cliente_email,
    PROVEEDOR_LABEL[e.proveedor] || e.proveedor, e.producto_nombre || '', e.tipo_entrega, e.cp_destino,
    e.dest_ciudad || '', e.dest_provincia || '', e.sucursal_nombre || e.sucursal_codigo || '',
    e.costo_cotizado, e.costo_cobrado, e.bonificado == 1 ? 'si' : 'no',
    e.estado_label || e.estado, e.estado_at, e.tracking_codigo || '', e.ext_order_id || '', e.pedido_estado,
  ]);

  const csv = [cab, ...filas].map(f => f.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(';')).join('\r\n');
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'envios-' + new Date().toISOString().slice(0, 10) + '.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function escAttr(str) {
  return escHtml(str).replace(/"/g, '&quot;');
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  if (!await checkAuth()) return;

  fetchEnvios();

  let t = null;
  document.getElementById('search-envios')?.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(fetchEnvios, 350);
  });
  ['filter-estado', 'filter-proveedor', 'filter-desde', 'filter-hasta'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', fetchEnvios);
  });
  document.getElementById('btn-limpiar-filtros')?.addEventListener('click', () => {
    ['search-envios', 'filter-estado', 'filter-proveedor', 'filter-desde', 'filter-hasta'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    fetchEnvios();
  });
  document.getElementById('btn-actualizar')?.addEventListener('click', fetchEnvios);
  document.getElementById('btn-exportar')?.addEventListener('click', exportarCSV);
});
