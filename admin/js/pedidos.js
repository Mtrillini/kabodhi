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
      <td>
        <span class="badge badge--${p.estado}">${capitalize(p.estado)}</span>
        ${p.metodo_pago === 'transferencia' ? `<br><span class="badge badge--${p.estado === 'pendiente' ? 'pendiente' : 'activo'}" style="margin-top:0.25rem;font-size:0.6rem;" title="Forma de pago">${p.estado === 'pendiente' ? 'Transferencia a confirmar' : 'Transferencia'}</span>` : ''}
        ${p.envio_estado && p.envio_estado !== 'pendiente' ? `<br><span class="badge envio-badge badge--${ENVIO_BADGE[p.envio_estado] || 'pendiente'}" style="margin-top:0.25rem;font-size:0.6rem;" title="Estado del envío">${escHtml(ENVIO_ESTADOS[p.envio_estado] || p.envio_estado)}</span>` : ''}
      </td>
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
            Pago
          </div>
          <div id="pago-${p.id}" style="font-size:0.78rem;color:var(--taupe);">Cargando pagos...</div>
        </div>

        <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--champagne);">
          <div style="font-size:0.7rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:var(--taupe);margin-bottom:0.6rem;">
            Envío
          </div>
          <div id="envio-${p.id}" style="font-size:0.78rem;color:var(--taupe);">Cargando envío...</div>

          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;margin-top:1rem;">
            <div style="flex:0 0 150px;">
              <label class="form-label" for="tr-transporte-${p.id}" style="font-size:0.65rem;">Transporte</label>
              <input type="text" id="tr-transporte-${p.id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;"
                     placeholder="Correo Argentino" value="${escAttr(p.transporte)}">
            </div>
            <div style="flex:0 0 170px;">
              <label class="form-label" for="tr-codigo-${p.id}" style="font-size:0.65rem;">Código de seguimiento</label>
              <input type="text" id="tr-codigo-${p.id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;"
                     placeholder="AR123456789" value="${escAttr(p.tracking_codigo)}">
            </div>
            <div style="flex:1 1 240px;">
              <label class="form-label" for="tr-url-${p.id}" style="font-size:0.65rem;">Link de seguimiento <span style="font-weight:400;">(vacío = el de Correo)</span></label>
              <input type="url" id="tr-url-${p.id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;"
                     placeholder="https://..." value="${escAttr(p.tracking_url)}">
            </div>
            <button class="btn btn-secondary btn-sm" onclick="guardarTracking(${p.id})">Guardar</button>
            <button class="btn btn-primary btn-sm" onclick="marcarEnviado(${p.id})"
                    title="Guarda el seguimiento, marca el envío como despachado y le manda el mail al cliente">
              Despachar y avisar al cliente
            </button>
          </div>
          <p style="font-size:0.68rem;color:var(--taupe);margin-top:0.5rem;">
            El mail con el seguimiento se manda al pasar el envío a <strong>Despachado</strong>.
            Cargá el código antes para que salga incluido.
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

  cargarPagos(id);
  cargarEnvio(id);
  cargarMails(id);
}

// ---- Pagos del pedido ----

const PAGO_BADGE = {
  approved: 'aprobado', pending: 'pendiente', in_process: 'pendiente', in_mediation: 'pendiente', authorized: 'pendiente',
  rejected: 'rechazado', cancelled: 'cancelado', refunded: 'cancelado', charged_back: 'rechazado',
};

async function cargarPagos(id) {
  const cont = document.getElementById('pago-' + id);
  if (!cont) return;
  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/pagos', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    renderPagos(id, json.data || []);
  } catch (err) {
    cont.innerHTML = `<span style="color:#c07b7b;">${escHtml(err.message)}</span>`;
  }
}

function renderPagos(id, pagos) {
  const cont   = document.getElementById('pago-' + id);
  const pedido = allPedidos.find(p => p.id === id) || {};
  if (!cont) return;

  const esTransf   = pedido.metodo_pago === 'transferencia';
  const ultimo     = pagos[0] || null;
  const aprobado   = pagos.find(g => g.estado === 'approved');
  const descuento  = parseFloat(pedido.descuento_monto || 0);

  const cabecera = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.3rem 1.5rem;color:var(--negro);">
      <div><span style="color:var(--taupe);">Forma de pago:</span> ${esTransf ? 'Transferencia bancaria' : 'Mercado Pago'}${descuento > 0 ? ` <span class="badge badge--aprobado" style="font-size:0.6rem;">${escHtml(String(parseFloat(pedido.descuento_pct)))}% desc. (−${formatMoney(descuento)})</span>` : ''}</div>
      <div><span style="color:var(--taupe);">Total del pedido:</span> <strong>${formatMoney(pedido.total)}</strong>${pedido.pagado_at ? ` · pagado ${formatDate(pedido.pagado_at)}` : ''}</div>
      ${aprobado && aprobado.proveedor === 'mercadopago' ? `
      <div><span style="color:var(--taupe);">Medio:</span> ${escHtml(aprobado.medio_label)}${parseFloat(aprobado.monto_cuota) > 0 && aprobado.cuotas > 1 ? ` (${aprobado.cuotas} × ${formatMoney(aprobado.monto_cuota)})` : ''}</div>
      <div><span style="color:var(--taupe);">Comisión MP:</span> ${formatMoney(aprobado.comision)} · <span style="color:var(--taupe);">neto:</span> <strong>${formatMoney(aprobado.neto ?? (aprobado.monto - aprobado.comision))}</strong>${parseFloat(aprobado.reembolsado) > 0 ? ` · reembolsado ${formatMoney(aprobado.reembolsado)}` : ''}</div>` : ''}
    </div>`;

  const lista = pagos.length ? `
    <div style="margin-top:0.7rem;">
      ${pagos.map(g => `
        <div style="display:flex;gap:0.6rem;align-items:baseline;padding:0.25rem 0;border-bottom:1px solid #f0ece6;font-size:0.72rem;flex-wrap:wrap;">
          <span style="white-space:nowrap;color:var(--taupe);">${formatDate(g.aprobado_at || g.created_at)}</span>
          <span class="badge badge--${PAGO_BADGE[g.estado] || 'pendiente'}" style="font-size:0.6rem;">${escHtml(g.estado_label)}</span>
          <span style="flex:1;">${escHtml(g.medio_label)}${g.estado_detalle && g.estado !== 'approved' ? ` <span style="color:var(--taupe);">(${escHtml(g.estado_detalle)})</span>` : ''}</span>
          <span style="white-space:nowrap;">${formatMoney(g.monto)}</span>
          <span style="white-space:nowrap;color:var(--taupe);font-family:monospace;">${g.referencia ? escHtml(g.referencia) : ''}</span>
          ${g.usuario ? `<span style="white-space:nowrap;color:var(--taupe);">${escHtml(g.usuario)}</span>` : ''}
        </div>`).join('')}
    </div>` : `<div style="margin-top:0.5rem;">Todavía no hay pagos registrados.</div>`;

  const puedeConfirmar = esTransf && ['pendiente', 'rechazado', 'cancelado'].includes(pedido.estado);
  // Reembolsar es solo del administrador principal (el backend exige super).
  const esSuper = !window.ADMIN_ACTUAL || window.ADMIN_ACTUAL.rol === 'super';
  const puedeReembolsar = esSuper && !!(aprobado && aprobado.proveedor === 'mercadopago' && parseFloat(aprobado.reembolsado) < parseFloat(aprobado.monto));

  const acciones = `
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;margin-top:0.9rem;">
      ${puedeConfirmar ? `
      <div style="flex:0 0 130px;">
        <label class="form-label" for="pg-monto-${id}" style="font-size:0.65rem;">Monto recibido</label>
        <input type="number" id="pg-monto-${id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;" step="0.01" value="${escAttr(pedido.total)}">
      </div>
      <div style="flex:1 1 180px;">
        <label class="form-label" for="pg-ref-${id}" style="font-size:0.65rem;">Referencia (opcional)</label>
        <input type="text" id="pg-ref-${id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;" placeholder="Nº de operación / comprobante" maxlength="100">
      </div>
      <button class="btn btn-primary btn-sm" onclick="confirmarTransferencia(${id})">Confirmar transferencia</button>` : ''}
      ${!esTransf ? `<button class="btn btn-secondary btn-sm" onclick="sincronizarPagos(${id})" title="Vuelve a leer los pagos de este pedido en Mercado Pago">Sincronizar con Mercado Pago</button>` : ''}
      ${puedeReembolsar ? `<button class="btn btn-secondary btn-sm" onclick="reembolsarPago(${id}, ${parseFloat(aprobado.monto) - parseFloat(aprobado.reembolsado)})">Reembolsar</button>` : ''}
    </div>
    ${puedeConfirmar ? `<p style="font-size:0.66rem;color:var(--taupe);margin-top:0.4rem;">Confirmar pasa el pedido a <strong>Aprobado</strong>, descuenta el stock y le avisa al cliente por mail.</p>` : ''}`;

  cont.innerHTML = cabecera + lista + acciones;
}

function refrescarPedidoLocal(id, pedido) {
  if (!pedido) return;
  const idx = allPedidos.findIndex(p => p.id === id);
  if (idx >= 0) Object.assign(allPedidos[idx], pedido, { items: undefined, envio: undefined });
  const badge = document.querySelector(`.pedido-row[data-id="${id}"] .badge:not(.envio-badge)`);
  if (badge) { badge.className = `badge badge--${pedido.estado}`; badge.textContent = capitalize(pedido.estado); }
  renderResumen();
}

window.confirmarTransferencia = async (id) => {
  const monto = document.getElementById('pg-monto-' + id)?.value || '';
  const ref   = document.getElementById('pg-ref-' + id)?.value.trim() || '';
  if (!confirm(`¿Confirmar que se recibió la transferencia del pedido #${id}?`)) return;
  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/pagos/confirmar-transferencia', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ monto, referencia: ref }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'No se pudo confirmar.');
    showToast(json.message, 'success');
    refrescarPedidoLocal(id, json.data.pedido);
    cargarPagos(id); cargarMails(id); cargarEnvio(id);
  } catch (err) {
    showToast(err.message, 'error');
  }
};

window.sincronizarPagos = async (id) => {
  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/pagos/sincronizar', { method: 'POST', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'No se pudo sincronizar.');
    showToast(json.message, 'success');
    refrescarPedidoLocal(id, json.data.pedido);
    renderPagos(id, json.data.pagos || []);
    cargarMails(id);
  } catch (err) {
    showToast(err.message, 'error');
  }
};

window.reembolsarPago = async (id, disponible) => {
  const txt = prompt(`Monto a reembolsar (máximo ${formatMoney(disponible)}). Dejá vacío para reembolsar el total:`, '');
  if (txt === null) return;
  const monto = txt.trim();
  if (!confirm(monto ? `¿Reembolsar ${formatMoney(parseFloat(monto))} del pedido #${id}?` : `¿Reembolsar el TOTAL del pedido #${id}? El pedido queda cancelado.`)) return;
  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/pagos/reembolsar', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ monto }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'No se pudo reembolsar.');
    showToast(json.message, 'success');
    refrescarPedidoLocal(id, json.data.pedido);
    cargarPagos(id); cargarMails(id); cargarEnvio(id);
  } catch (err) {
    showToast(err.message, 'error');
  }
};

// ---- Envío del pedido ----

/** Estados del envio (se reemplazan por los que manda la API). */
let ENVIO_ESTADOS = {
  pendiente: 'Pendiente de despacho', preparando: 'En preparación', enviado: 'Despachado',
  en_traslado: 'En traslado', en_sucursal: 'En sucursal, listo para retirar', entregado: 'Entregado',
  rechazado: 'Rechazado por el destinatario', devuelto: 'Devuelto al remitente', cancelado: 'Cancelado',
};

const ENVIO_BADGE = {
  pendiente: 'pendiente', preparando: 'pendiente', enviado: 'enviado', en_traslado: 'enviado',
  en_sucursal: 'enviado', entregado: 'entregado', rechazado: 'rechazado', devuelto: 'rechazado', cancelado: 'cancelado',
};

const PROVEEDOR_LABEL = { correo: 'Correo Argentino', tabla: 'Tarifa de la tienda', sin_costo: 'Sin costo' };

/** Trae el registro del envio (con historial) y lo pinta en el detalle. */
async function cargarEnvio(id) {
  const cont = document.getElementById('envio-' + id);
  if (!cont) return;

  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/envio', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    if (json.estados) ENVIO_ESTADOS = json.estados;
    renderEnvio(id, json.data);
  } catch (err) {
    cont.innerHTML = `<span style="color:#c07b7b;">${escHtml(err.message)}</span>`;
  }
}

function renderEnvio(id, e) {
  const cont = document.getElementById('envio-' + id);
  if (!cont) return;

  const pedido    = allPedidos.find(p => p.id === id) || {};
  const cotizado  = parseFloat(e.costo_cotizado || 0);
  const cobrado   = parseFloat(e.costo_cobrado  || 0);
  const esCorreo  = e.proveedor === 'correo';
  const importado = !!e.importado_at;
  const final     = ['entregado', 'devuelto', 'cancelado'].includes(e.estado);

  const dato = (label, valor) => valor
    ? `<div><span style="color:var(--taupe);">${label}:</span> ${valor}</div>` : '';

  const resumen = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.3rem 1.5rem;color:var(--negro);">
      ${dato('Servicio', escHtml(PROVEEDOR_LABEL[e.proveedor] || e.proveedor) + (e.producto_nombre ? ' · ' + escHtml(e.producto_nombre) : ''))}
      ${dato('Entrega', e.tipo_entrega === 'sucursal'
        ? 'Retiro en sucursal' + (e.sucursal_nombre ? ' — ' + escHtml(e.sucursal_nombre) : (e.sucursal_codigo ? ' #' + escHtml(e.sucursal_codigo) : ''))
        : 'A domicilio')}
      ${dato('Destino', 'CP ' + escHtml(e.cp_destino) + (e.dest_ciudad ? ', ' + escHtml(e.dest_ciudad) : '') + (e.dest_provincia ? ', ' + escHtml(e.dest_provincia) : ''))}
      ${dato('Costo', `cotizado ${formatMoney(cotizado)} · cobrado <strong>${formatMoney(cobrado)}</strong>${e.bonificado == 1 ? ' <span class="badge badge--aprobado" style="font-size:0.6rem;">bonificado</span>' : ''}`)}
      ${dato('Plazo', e.plazo_min_dias || e.plazo_max_dias
        ? `${e.plazo_min_dias || e.plazo_max_dias}${e.plazo_max_dias && e.plazo_min_dias !== e.plazo_max_dias ? ' a ' + e.plazo_max_dias : ''} días hábiles` : '')}
      ${dato('Bulto', parseInt(e.peso_gramos) > 0 ? `${e.peso_gramos} g · ${e.alto_cm}×${e.ancho_cm}×${e.largo_cm} cm` : '')}
      ${dato('MiCorreo', importado ? `orden ${escHtml(e.ext_order_id)} · ${formatDate(e.importado_at)}` : '')}
      ${dato('Seguimiento', e.tracking_codigo
        ? `<span style="font-family:monospace;">${escHtml(e.tracking_codigo)}</span>${e.tracking_url_publica ? ` · <a href="${escAttr(e.tracking_url_publica)}" target="_blank" style="color:var(--negro);">ver</a>` : ''}` : '')}
    </div>`;

  const opcionesEstado = Object.entries(ENVIO_ESTADOS)
    .map(([k, v]) => `<option value="${k}" ${e.estado === k ? 'selected' : ''}>${escHtml(v)}</option>`).join('');

  const acciones = `
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;margin-top:0.9rem;">
      <div style="flex:0 0 auto;">
        <span class="badge badge--${ENVIO_BADGE[e.estado] || 'pendiente'}" style="font-size:0.66rem;">${escHtml(e.estado_label || e.estado)}</span>
        <span style="font-size:0.66rem;color:var(--taupe);margin-left:0.4rem;">${formatDate(e.estado_at)}</span>
      </div>
      <div style="flex:0 0 220px;">
        <label class="form-label" for="ev-estado-${id}" style="font-size:0.65rem;">Cambiar estado del envío</label>
        <select id="ev-estado-${id}" class="form-select" style="padding:0.4rem 0.6rem;font-size:0.78rem;">${opcionesEstado}</select>
      </div>
      <div style="flex:1 1 200px;">
        <label class="form-label" for="ev-detalle-${id}" style="font-size:0.65rem;">Nota (opcional)</label>
        <input type="text" id="ev-detalle-${id}" class="form-input" style="padding:0.4rem 0.6rem;font-size:0.78rem;" placeholder="Ej: lo retiró el cliente" maxlength="500">
      </div>
      <button class="btn btn-secondary btn-sm" onclick="cambiarEstadoEnvio(${id})">Actualizar</button>
      ${!importado && !final ? `
      <button class="btn btn-primary btn-sm" onclick="importarEnvioCorreo(${id})"
              title="Crea la orden de envío en MiCorreo con los datos del pedido${pedido.estado !== 'aprobado' && pedido.estado !== 'enviado' ? ' (el pedido tiene que estar aprobado)' : ''}"
              ${pedido.estado !== 'aprobado' && pedido.estado !== 'enviado' ? 'disabled' : ''}>
        Generar envío en Correo Argentino
      </button>` : ''}
      <button class="btn btn-secondary btn-sm" onclick="toggleDestino(${id})">Datos de destino</button>
    </div>
    <p style="font-size:0.66rem;color:var(--taupe);margin-top:0.4rem;">
      <strong>Despachado</strong> y <strong>Entregado</strong> también cambian el estado del pedido y avisan al cliente por mail;
      <strong>En sucursal</strong> le avisa que ya puede retirarlo. Los demás son internos.
    </p>`;

  const destino = `
    <div id="destino-${id}" hidden style="margin-top:0.9rem;padding:0.9rem;background:#faf8f4;border:1px solid var(--champagne);border-radius:6px;">
      <div style="font-size:0.66rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:var(--taupe);margin-bottom:0.6rem;">
        Datos de destino (lo que se manda a Correo Argentino)
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0.5rem;">
        ${campoDestino(id, 'dest_calle',      'Calle',        e.dest_calle)}
        ${campoDestino(id, 'dest_numero',     'Número',       e.dest_numero)}
        ${campoDestino(id, 'dest_piso_depto', 'Piso / Depto', e.dest_piso_depto)}
        ${campoDestino(id, 'dest_ciudad',     'Ciudad',       e.dest_ciudad)}
        ${campoDestino(id, 'dest_provincia',  'Provincia',    e.dest_provincia)}
        ${campoDestino(id, 'cp_destino',      'CP',           e.cp_destino)}
        ${campoDestino(id, 'sucursal_codigo', 'Sucursal (código)', e.sucursal_codigo)}
        ${campoDestino(id, 'peso_gramos',     'Peso (g)',     e.peso_gramos, 'number')}
        ${campoDestino(id, 'alto_cm',         'Alto (cm)',    e.alto_cm,  'number')}
        ${campoDestino(id, 'ancho_cm',        'Ancho (cm)',   e.ancho_cm, 'number')}
        ${campoDestino(id, 'largo_cm',        'Largo (cm)',   e.largo_cm, 'number')}
      </div>
      <div style="margin-top:0.6rem;display:flex;gap:0.5rem;align-items:center;">
        <button class="btn btn-secondary btn-sm" onclick="guardarDestino(${id})">Guardar destino</button>
        <span style="font-size:0.66rem;color:var(--taupe);">Los pedidos anteriores a la integración pueden tener la calle y el número sin separar.</span>
      </div>
    </div>`;

  const historial = (e.historial || []).length ? `
    <div style="margin-top:0.9rem;">
      <div style="font-size:0.66rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:var(--taupe);margin-bottom:0.4rem;">Historial</div>
      ${e.historial.slice().reverse().map(h => `
        <div style="display:flex;gap:0.6rem;align-items:baseline;padding:0.25rem 0;border-bottom:1px solid #f0ece6;font-size:0.72rem;">
          <span style="white-space:nowrap;color:var(--taupe);">${formatDate(h.created_at)}</span>
          <span class="badge badge--${ENVIO_BADGE[h.estado] || 'pendiente'}" style="font-size:0.6rem;">${escHtml(h.estado_label || h.estado)}</span>
          <span style="flex:1;">${escHtml(h.detalle || '')}</span>
          <span style="white-space:nowrap;color:var(--taupe);">${h.usuario ? escHtml(h.usuario) : (h.origen === 'correo' ? 'Correo' : 'sistema')}</span>
        </div>`).join('')}
    </div>` : '';

  cont.innerHTML = resumen + acciones + destino + historial;

  // Reflejar en la lista y en los inputs de tracking.
  const idx = allPedidos.findIndex(p => p.id === id);
  if (idx >= 0) {
    allPedidos[idx].envio_estado = e.estado;
    if (e.tracking_codigo && !allPedidos[idx].tracking_codigo) {
      allPedidos[idx].tracking_codigo = e.tracking_codigo;
      const inp = document.getElementById('tr-codigo-' + id);
      if (inp && !inp.value) inp.value = e.tracking_codigo;
    }
  }
  const badgeEnvio = document.querySelector(`.pedido-row[data-id="${id}"] .envio-badge`);
  if (badgeEnvio) {
    badgeEnvio.className   = `badge envio-badge badge--${ENVIO_BADGE[e.estado] || 'pendiente'}`;
    badgeEnvio.textContent = e.estado_label || e.estado;
  }
}

function campoDestino(id, campo, label, valor, tipo = 'text') {
  return `
    <div>
      <label class="form-label" for="dst-${campo}-${id}" style="font-size:0.62rem;">${label}</label>
      <input type="${tipo}" id="dst-${campo}-${id}" class="form-input" style="padding:0.35rem 0.5rem;font-size:0.74rem;"
             value="${escAttr(valor)}" ${tipo === 'number' ? 'min="0"' : ''}>
    </div>`;
}

window.toggleDestino = (id) => {
  const box = document.getElementById('destino-' + id);
  if (box) box.hidden = !box.hidden;
};

window.guardarDestino = async (id) => {
  const campos = ['dest_calle', 'dest_numero', 'dest_piso_depto', 'dest_ciudad', 'dest_provincia', 'cp_destino',
                  'sucursal_codigo', 'peso_gramos', 'alto_cm', 'ancho_cm', 'largo_cm'];
  const body = {};
  campos.forEach(c => { body[c] = document.getElementById(`dst-${c}-${id}`)?.value.trim() ?? ''; });

  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/envio/destino', {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al guardar.');
    showToast('Datos de destino guardados.', 'success');
    renderEnvio(id, json.data);
    document.getElementById('destino-' + id).hidden = false;
  } catch (err) {
    showToast(err.message, 'error');
  }
};

window.cambiarEstadoEnvio = async (id) => {
  const estado  = document.getElementById('ev-estado-' + id)?.value;
  const detalle = document.getElementById('ev-detalle-' + id)?.value.trim() || '';
  if (!estado) return;

  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/envio/estado', {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ estado, detalle }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al actualizar el envío.');

    showToast(`Envío del pedido #${id}: ${ENVIO_ESTADOS[estado] || estado}.`, 'success');
    renderEnvio(id, json.data);

    // Despachado / entregado tambien mueven el pedido: se refresca la fila.
    if (estado === 'enviado' || estado === 'entregado') {
      const idx = allPedidos.findIndex(p => p.id === id);
      if (idx >= 0) allPedidos[idx].estado = estado;
      const badge = document.querySelector(`.pedido-row[data-id="${id}"] .badge:not(.envio-badge)`);
      if (badge) { badge.className = `badge badge--${estado}`; badge.textContent = capitalize(estado); }
      renderResumen();
      cargarMails(id);
    }
  } catch (err) {
    showToast(err.message, 'error');
  }
};

window.importarEnvioCorreo = async (id) => {
  if (!confirm(`¿Crear la orden de envío del pedido #${id} en MiCorreo (Correo Argentino)?`)) return;

  const btn = document.querySelector(`#envio-${id} .btn-primary`);
  if (btn) { btn.disabled = true; btn.textContent = 'Generando...'; }

  try {
    const res  = await fetch(API_URL + '/pedidos/' + id + '/envio/importar', { method: 'POST', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'No se pudo generar el envío.');
    showToast(json.message, 'success');
    renderEnvio(id, json.data);
  } catch (err) {
    showToast(err.message, 'error');
    if (btn) { btn.disabled = false; btn.textContent = 'Generar envío en Correo Argentino'; }
  }
};

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
    cargarEnvio(id);
    cargarMails(id);
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

  await fetchPedidos();

  // Desde el historial de envios se llega con #pedido-N: se abre ese detalle.
  const m = location.hash.match(/^#pedido-(\d+)$/);
  if (m) {
    const id = parseInt(m[1]);
    if (document.getElementById('detalle-' + id)) {
      toggleDetalle(id);
      document.querySelector(`.pedido-row[data-id="${id}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

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
