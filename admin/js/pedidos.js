// ============================================================
// KABODHI Admin — pedidos.js
// ============================================================

// Estados en los que la venta ya se cobro.
const ESTADOS_COBRADOS = ['aprobado', 'enviado', 'entregado'];

let allPedidos      = [];   // lo que devolvio la API (ya filtrado por estado)
let pedidosVisibles = [];   // lo que se ve tras aplicar busqueda y fechas
let openDetailId    = null;

// ---- Fetch ----
async function fetchPedidos(estado = '') {
  try {
    showTableLoading();
    const url  = API_URL + '/pedidos' + (estado ? '?estado=' + estado : '');
    const res  = await fetch(url, { credentials: 'include' });
    const json = await res.json();

    if (!json.success) throw new Error(json.message || 'Error al cargar pedidos.');

    allPedidos = json.data || [];
    aplicarFiltros();
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

// ---- Filtros (busqueda y rango de fechas, del lado del cliente) ----
function aplicarFiltros() {
  const q     = (document.getElementById('search-pedidos')?.value || '').toLowerCase().trim();
  const desde = document.getElementById('filter-desde')?.value || '';
  const hasta = document.getElementById('filter-hasta')?.value || '';

  pedidosVisibles = allPedidos.filter(p => {
    if (q) {
      const enTexto =
        String(p.id).includes(q) ||
        (p.cliente_nombre || '').toLowerCase().includes(q) ||
        (p.cliente_email  || '').toLowerCase().includes(q);
      if (!enTexto) return false;
    }

    // created_at viene como "YYYY-MM-DD HH:MM:SS": los primeros 10 chars
    // comparan bien contra el value de un <input type="date">.
    const fecha = (p.created_at || '').slice(0, 10);
    if (desde && fecha < desde) return false;
    if (hasta && fecha > hasta) return false;

    return true;
  });

  renderTabla(pedidosVisibles);
  renderResumen();
}

function renderResumen() {
  const el = document.getElementById('resumen-filtros');
  if (!el) return;

  const total = pedidosVisibles.reduce((sum, p) => sum + parseFloat(p.total || 0), 0);
  // Un pedido enviado o entregado tambien es una venta cobrada.
  const cobrados  = pedidosVisibles.filter(p => ESTADOS_COBRADOS.includes(p.estado));
  const facturado = cobrados.reduce((sum, p) => sum + parseFloat(p.total || 0), 0);

  if (!allPedidos.length) { el.textContent = ''; return; }

  el.innerHTML = `
    <strong>${pedidosVisibles.length}</strong> de ${allPedidos.length} pedido(s)
    &nbsp;·&nbsp; Suma: <strong>${formatMoney(total)}</strong>
    &nbsp;·&nbsp; Cobrados (${cobrados.length}): <strong>${formatMoney(facturado)}</strong>
  `;
}

function limpiarFiltros() {
  const s = document.getElementById('search-pedidos');
  const d = document.getElementById('filter-desde');
  const h = document.getElementById('filter-hasta');
  const e = document.getElementById('filter-estado');
  if (s) s.value = '';
  if (d) d.value = '';
  if (h) h.value = '';
  if (e) e.value = '';
  fetchPedidos('');
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
        <div style="display:flex;gap:0.4rem;align-items:center;">
          <select
            class="filter-select"
            style="font-size:0.68rem;padding:0.3rem 0.5rem;"
            onchange="actualizarEstado(${p.id}, this.value)"
          >
            <option value="">Cambiar estado</option>
            <option value="pendiente"  ${p.estado === 'pendiente'  ? 'selected' : ''}>Pendiente</option>
            <option value="aprobado"   ${p.estado === 'aprobado'   ? 'selected' : ''}>Aprobado</option>
            <option value="enviado"    ${p.estado === 'enviado'    ? 'selected' : ''}>Enviado</option>
            <option value="entregado"  ${p.estado === 'entregado'  ? 'selected' : ''}>Entregado</option>
            <option value="rechazado"  ${p.estado === 'rechazado'  ? 'selected' : ''}>Rechazado</option>
            <option value="cancelado"  ${p.estado === 'cancelado'  ? 'selected' : ''}>Cancelado</option>
          </select>
          <button class="btn btn-secondary btn-sm" onclick="imprimirRemito(${p.id})" title="Imprimir remito">🖶</button>
        </div>
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
          ${p.cliente_dni ? `<div style="font-size:0.72rem;color:var(--taupe);">DNI: ${escHtml(p.cliente_dni)}</div>` : ''}
          ${p.cliente_direccion ? `<div style="font-size:0.72rem;color:var(--taupe);">Dir: ${escHtml(p.cliente_direccion)}</div>` : ''}
        </div>

        <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--champagne);">
          <div style="font-size:0.7rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:var(--taupe);margin-bottom:0.6rem;">
            Seguimiento del envío
          </div>
          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:0 0 150px;">
              <label class="form-label" for="tr-transporte-${p.id}" style="font-size:0.65rem;">Transporte</label>
              <input type="text" id="tr-transporte-${p.id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;"
                     placeholder="Andreani" value="${escAttr(p.transporte)}">
            </div>
            <div style="flex:0 0 170px;">
              <label class="form-label" for="tr-codigo-${p.id}" style="font-size:0.65rem;">Código</label>
              <input type="text" id="tr-codigo-${p.id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;"
                     placeholder="AR123456789" value="${escAttr(p.tracking_codigo)}">
            </div>
            <div style="flex:1 1 240px;">
              <label class="form-label" for="tr-url-${p.id}" style="font-size:0.65rem;">Link de seguimiento</label>
              <input type="url" id="tr-url-${p.id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;"
                     placeholder="https://..." value="${escAttr(p.tracking_url)}">
            </div>
            <button class="btn btn-secondary btn-sm" onclick="guardarTracking(${p.id})">Guardar</button>
            <button class="btn btn-primary btn-sm" onclick="marcarEnviado(${p.id})"
                    title="Guarda el seguimiento, marca el pedido como enviado y le manda el mail al cliente">
              Guardar y avisar al cliente
            </button>
          </div>
          <p style="font-size:0.68rem;color:var(--taupe);margin-top:0.5rem;">
            El mail con el seguimiento se manda al pasar el pedido a <strong>Enviado</strong>.
            Cargá estos datos antes para que salgan incluidos.
          </p>
        </div>

        <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--champagne);">
          <div style="font-size:0.7rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:var(--taupe);margin-bottom:0.6rem;">
            Mails enviados
          </div>
          <div id="mails-${p.id}" style="font-size:0.72rem;color:var(--taupe);">Cargando...</div>
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

  try {
    const pedido = await getPedido(id);
    renderDetalle(id, pedido);
  } catch (err) {
    const itemsEl = document.getElementById('items-' + id);
    if (itemsEl) itemsEl.innerHTML = `<div style="color:#c07b7b;font-size:0.78rem;">${err.message}</div>`;
  }

  cargarMails(id);
}

/** Historial de mails del pedido, para saber que le llego al cliente. */
async function cargarMails(id) {
  const cont = document.getElementById('mails-' + id);
  if (!cont) return;

  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/mails', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);

    const mails = json.data || [];
    if (!mails.length) {
      cont.textContent = 'Todavía no se envió ningún mail por este pedido.';
      return;
    }

    cont.innerHTML = mails.map(m => `
      <div style="display:flex;gap:0.5rem;align-items:baseline;padding:0.25rem 0;border-bottom:1px solid #f0ece6;">
        <span class="badge badge--${m.exito == 1 ? 'aprobado' : 'rechazado'}" style="font-size:0.6rem;">
          ${m.exito == 1 ? 'Enviado' : 'No enviado'}
        </span>
        <span style="flex:1;">${escHtml(m.asunto)}</span>
        <span style="white-space:nowrap;">${escHtml(m.destino)}</span>
        <span style="white-space:nowrap;">${formatDate(m.created_at)}</span>
      </div>
      ${m.error ? `<div style="font-size:0.66rem;color:#c07b7b;padding:0.15rem 0 0.4rem;">${escHtml(m.error)}</div>` : ''}
    `).join('');
  } catch (err) {
    cont.innerHTML = `<span style="color:#c07b7b;">${err.message}</span>`;
  }
}

window.toggleDetalle = toggleDetalle;

/** Trae un pedido con sus items desde la API. */
async function getPedido(id) {
  const res  = await fetch(API_URL + '/pedidos/' + id, { credentials: 'include' });
  const json = await res.json();
  if (!json.success) throw new Error(json.message || 'Error al cargar el pedido.');
  return json.data;
}

// ---- Render detail items ----
function renderDetalle(id, pedido) {
  const itemsEl = document.getElementById('items-' + id);
  if (!itemsEl) return;

  if (!pedido.items || !pedido.items.length) {
    itemsEl.innerHTML = `<div style="color:var(--taupe);font-size:0.78rem;">Sin items.</div>`;
    return;
  }

  const envio    = parseFloat(pedido.envio_costo || 0);
  const subtotal = pedido.items.reduce(
    (sum, i) => sum + parseFloat(i.precio_unitario) * parseInt(i.cantidad), 0
  );

  itemsEl.innerHTML = pedido.items.map(item => `
    <div class="detail-item">
      <span>
        ${escHtml(item.producto_nombre || 'Producto #' + item.producto_id)}
        <span style="color:var(--taupe);"> × ${item.cantidad}</span>
      </span>
      <span>${formatMoney(item.precio_unitario * item.cantidad)}</span>
    </div>
  `).join('') + `
    <div class="detail-item" style="margin-top:0.5rem;color:var(--taupe);">
      <span>Subtotal</span>
      <span>${formatMoney(subtotal)}</span>
    </div>
    <div class="detail-item" style="color:var(--taupe);">
      <span>Envío${pedido.envio_descripcion ? ' — ' + escHtml(pedido.envio_descripcion) : ''}</span>
      <span>${envio > 0 ? formatMoney(envio) : 'Sin cargo'}</span>
    </div>
    <div class="detail-item" style="font-weight:600;">
      <span>Total</span>
      <span>${formatMoney(pedido.total)}</span>
    </div>
  `;
}

// ---- Update status ----
async function actualizarEstado(id, estado, silencioso = false) {
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

    if (!silencioso) showToast(`Pedido #${id}: estado actualizado a "${estado}".`, 'success');

    // Update local data and re-render
    const idx = allPedidos.findIndex(p => p.id === id);
    if (idx >= 0) allPedidos[idx].estado = estado;

    // Re-render badge in the row
    const badge = document.querySelector(`.pedido-row[data-id="${id}"] .badge`);
    if (badge) {
      badge.className = `badge badge--${estado}`;
      badge.textContent = capitalize(estado);
    }

    renderResumen();

  } catch (err) {
    showToast(err.message, 'error');
    // Re-fetch to restore correct state
    fetchPedidos(document.getElementById('filter-estado')?.value || '');
  }
}

window.actualizarEstado = actualizarEstado;

// ---- Seguimiento ----
function leerTracking(id) {
  return {
    transporte:      document.getElementById('tr-transporte-' + id)?.value.trim() || '',
    tracking_codigo: document.getElementById('tr-codigo-' + id)?.value.trim()     || '',
    tracking_url:    document.getElementById('tr-url-' + id)?.value.trim()        || '',
  };
}

/**
 * Guarda transporte / codigo / link sin tocar el estado ni mandar mails.
 * Nombre distinto al handler expuesto en window: una declaracion de funcion
 * top-level vive en window, asi que reusar el nombre la pisaria y el handler
 * terminaria llamandose a si mismo.
 */
async function persistirTracking(id, silencioso = false) {
  const res  = await fetch(API_URL + '/pedidos/' + id + '/tracking', {
    method: 'PUT',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(leerTracking(id)),
  });
  const json = await res.json();
  if (!json.success) throw new Error(json.message || 'Error al guardar el seguimiento.');

  // Reflejar en la copia local para no perder los datos al re-renderizar.
  const idx = allPedidos.findIndex(p => p.id === id);
  if (idx >= 0) Object.assign(allPedidos[idx], leerTracking(id));

  if (!silencioso) showToast('Seguimiento guardado.', 'success');
  return json.data;
}

window.guardarTracking = async (id) => {
  try { await persistirTracking(id); }
  catch (err) { showToast(err.message, 'error'); }
};

/** Guarda el seguimiento y recien despues pasa a "enviado", para que el mail lo incluya. */
async function marcarEnviado(id) {
  const datos = leerTracking(id);
  if (!datos.transporte && !datos.tracking_codigo && !datos.tracking_url) {
    if (!confirm('No cargaste datos de seguimiento. ¿Avisar igual que el pedido salió?')) return;
  }

  try {
    await persistirTracking(id, true);
    await actualizarEstado(id, 'enviado', true);
    showToast('Pedido marcado como enviado. Se le avisó al cliente.', 'success');
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.marcarEnviado = marcarEnviado;

// ---- Exportar CSV ----
function exportarCSV() {
  if (!pedidosVisibles.length) {
    showToast('No hay pedidos para exportar.', 'error');
    return;
  }

  const cabecera = ['#', 'Cliente', 'Email', 'Teléfono', 'DNI', 'Dirección', 'Envío', 'Total',
                    'Estado', 'Fecha', 'Transporte', 'Seguimiento'];

  const filas = pedidosVisibles.map(p => [
    p.id,
    p.cliente_nombre,
    p.cliente_email,
    p.cliente_telefono,
    p.cliente_dni,
    p.cliente_direccion,
    p.envio_costo,
    p.total,
    p.estado,
    p.created_at,
    p.transporte,
    p.tracking_codigo,
  ]);

  const csv = [cabecera, ...filas].map(fila => fila.map(csvCampo).join(';')).join('\r\n');

  // BOM para que Excel en es-AR abra los acentos bien.
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `pedidos-kabodhi-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);

  showToast(`${pedidosVisibles.length} pedido(s) exportado(s).`, 'success');
}

/** Escapa un campo CSV: comillas dobles y separador ; */
function csvCampo(valor) {
  const texto = valor === null || valor === undefined ? '' : String(valor);
  return `"${texto.replace(/"/g, '""')}"`;
}

// ---- Remito imprimible ----
async function imprimirRemito(id) {
  let pedido;
  try {
    pedido = await getPedido(id);
  } catch (err) {
    showToast(err.message, 'error');
    return;
  }

  const envio    = parseFloat(pedido.envio_costo || 0);
  const subtotal = (pedido.items || []).reduce(
    (sum, i) => sum + parseFloat(i.precio_unitario) * parseInt(i.cantidad), 0
  );

  const filas = (pedido.items || []).map(i => `
    <tr>
      <td>${escHtml(i.producto_nombre || 'Producto #' + i.producto_id)}</td>
      <td class="num">${i.cantidad}</td>
      <td class="num">${formatMoney(i.precio_unitario)}</td>
      <td class="num">${formatMoney(i.precio_unitario * i.cantidad)}</td>
    </tr>
  `).join('');

  const html = `<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<title>Remito #${pedido.id} — KABODHI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  /* Mismas fuentes que el resto de la app: Playfair para la marca y los
     titulos, Lato para el texto. */
  body { font-family: 'Lato', Helvetica, Arial, sans-serif; color: #1C3A4F; margin: 2.5rem; font-size: 13px; }
  h1 { font-family: 'Playfair Display', Georgia, serif; font-size: 1.6rem; margin: 0; letter-spacing: 0.15em; font-weight: 500; }
  .sub { color: #8B7966; font-size: 0.75rem; letter-spacing: 0.1em; text-transform: uppercase; }
  .head { display: flex; justify-content: space-between; align-items: flex-start;
          border-bottom: 2px solid #1C3A4F; padding-bottom: 1rem; margin-bottom: 1.5rem; }
  .meta { text-align: right; font-size: 0.8rem; }
  .bloque { margin-bottom: 1.5rem; }
  .bloque h2 { font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase;
               color: #8B7966; margin: 0 0 0.4rem; font-weight: normal; }
  table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
  th { text-align: left; font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase;
       color: #8B7966; border-bottom: 1px solid #A66B3D; padding: 0.5rem 0.4rem; font-weight: normal; }
  td { padding: 0.55rem 0.4rem; border-bottom: 1px solid #eee; }
  .num { text-align: right; white-space: nowrap; }
  .totales { margin-left: auto; width: 280px; margin-top: 1rem; }
  .totales tr td { border: none; padding: 0.3rem 0.4rem; }
  .totales .total td { border-top: 2px solid #1C3A4F; font-weight: 700; font-size: 1.05rem; padding-top: 0.6rem; }
  .meta .num { font-family: 'Playfair Display', Georgia, serif; font-size: 1.2rem; font-weight: 500; }
  .pie { margin-top: 3rem; font-size: 0.7rem; color: #8B7966; text-align: center;
         border-top: 1px solid #eee; padding-top: 1rem; }
  @media print { body { margin: 1.5cm; } }
</style></head>
<body>
  <div class="head">
    <div>
      <h1>KABODHI</h1>
      <div class="sub">Adaptógenos naturales</div>
    </div>
    <div class="meta">
      <div class="num">Remito #${pedido.id}</div>
      <div class="sub">${formatDate(pedido.created_at)}</div>
      <div class="sub">Estado: ${capitalize(pedido.estado)}</div>
    </div>
  </div>

  <div class="bloque">
    <h2>Cliente</h2>
    <div><strong>${escHtml(pedido.cliente_nombre)}</strong></div>
    <div>${escHtml(pedido.cliente_email)}</div>
    ${pedido.cliente_telefono ? `<div>Tel: ${escHtml(pedido.cliente_telefono)}</div>` : ''}
    ${pedido.cliente_dni ? `<div>DNI: ${escHtml(pedido.cliente_dni)}</div>` : ''}
  </div>

  ${pedido.cliente_direccion ? `
  <div class="bloque">
    <h2>Dirección de entrega</h2>
    <div>${escHtml(pedido.cliente_direccion)}</div>
  </div>` : ''}

  <div class="bloque">
    <h2>Detalle</h2>
    <table>
      <thead>
        <tr><th>Producto</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">Subtotal</th></tr>
      </thead>
      <tbody>${filas || '<tr><td colspan="4">Sin items.</td></tr>'}</tbody>
    </table>

    <table class="totales">
      <tr><td>Subtotal</td><td class="num">${formatMoney(subtotal)}</td></tr>
      <tr>
        <td>Envío${pedido.envio_descripcion ? ' — ' + escHtml(pedido.envio_descripcion) : ''}</td>
        <td class="num">${envio > 0 ? formatMoney(envio) : 'Sin cargo'}</td>
      </tr>
      <tr class="total"><td>Total</td><td class="num">${formatMoney(pedido.total)}</td></tr>
    </table>
  </div>

  <div class="pie">Gracias por tu compra · kabodhi.com</div>
</body></html>`;

  const win = window.open('', '_blank');
  if (!win) {
    showToast('El navegador bloqueó la ventana. Permití los pop-ups para imprimir.', 'error');
    return;
  }
  win.document.write(html);
  win.document.close();
  win.focus();
  // Esperar al render antes de abrir el diálogo de impresión.
  win.addEventListener('load', () => win.print());
  setTimeout(() => { try { win.print(); } catch { /* ya se imprimió */ } }, 400);
}
window.imprimirRemito = imprimirRemito;

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

// Para interpolar dentro de un atributo HTML entre comillas dobles.
function escAttr(str) {
  return escHtml(str).replace(/"/g, '&quot;');
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

  // El estado se filtra en el servidor; busqueda y fechas, en el cliente.
  document.getElementById('filter-estado')?.addEventListener('change', function () {
    fetchPedidos(this.value);
  });

  document.getElementById('search-pedidos')?.addEventListener('input', aplicarFiltros);
  document.getElementById('filter-desde')?.addEventListener('change', aplicarFiltros);
  document.getElementById('filter-hasta')?.addEventListener('change', aplicarFiltros);

  document.getElementById('btn-limpiar-filtros')?.addEventListener('click', limpiarFiltros);
  document.getElementById('btn-exportar')?.addEventListener('click', exportarCSV);
  document.getElementById('btn-actualizar')?.addEventListener('click', () => {
    fetchPedidos(document.getElementById('filter-estado')?.value || '');
  });
});
