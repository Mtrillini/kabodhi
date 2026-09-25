// ============================================================
// KABODHI — checkout.js
// ============================================================

// ---- Envío helpers ----
function getEnvioGuardado() {
  return window.Envio ? window.Envio.get() : null;
}

/** Provincias con el codigo que usa Correo Argentino (se cargan de la API). */
let PROVINCIAS = {};

/** Configuracion de pagos (cuotas, transferencia). Se carga al iniciar. */
let PAGO_CONFIG = { mp_disponible: true, mp_cuotas_max: 12, mp_mostrar_cuotas: true, transferencia: { activa: false, descuento: 0 } };

function metodoPagoElegido() {
  const el = document.querySelector('input[name="metodo-pago"]:checked');
  const v  = el ? el.value : 'mercadopago';
  if (v === 'transferencia' && PAGO_CONFIG.transferencia.activa) return 'transferencia';
  // Si MP no esta disponible y la transferencia si, es la unica opcion.
  if (!PAGO_CONFIG.mp_disponible && PAGO_CONFIG.transferencia.activa) return 'transferencia';
  return 'mercadopago';
}

/** true si hay al menos una forma de pago habilitada. */
function hayFormaDePago() {
  return PAGO_CONFIG.mp_disponible || PAGO_CONFIG.transferencia.activa;
}

/** Descuento por transferencia: sobre los productos, no sobre el envio (igual que el servidor). */
function descuentoActual(subtotal) {
  if (metodoPagoElegido() !== 'transferencia') return 0;
  const pct = PAGO_CONFIG.transferencia.descuento || 0;
  return pct > 0 ? Math.round(subtotal * pct) / 100 : 0;
}

// ---- Render order summary in checkout ----
function renderCheckoutSummary() {
  const container = document.getElementById('checkout-summary');
  if (!container) return;

  const carrito = window.Carrito ? window.Carrito.get() : { items: [] };
  const fmt     = window.formatMoney || (v => '$' + v);

  if (!carrito.items || carrito.items.length === 0) {
    container.innerHTML = `
      <div class="order-summary">
        <div class="order-summary__title">Tu pedido</div>
        <p style="font-family:var(--font-secondary);font-size:0.8rem;color:#888;">El carrito está vacío.</p>
      </div>
    `;
    return;
  }

  const subtotal   = window.Carrito.getTotal();
  const envio      = getEnvioGuardado();
  const envioTotal = envio ? parseFloat(envio.precio) : 0;
  const descuento  = descuentoActual(subtotal);
  const total      = subtotal - descuento + envioTotal;

  const itemsHTML = carrito.items.map(item => {
    if (item.esCombo) {
      const lista = (item.picks || [])
        .map(p => p.nombre + (p.variante_nombre ? ' — ' + p.variante_nombre : ''))
        .join(', ');
      return `
        <div class="order-summary__row">
          <span class="order-summary__item-name">${item.promo_nombre} <span class="order-summary__item-qty">(combo)</span></span>
          <span>${fmt(item.precio)}</span>
        </div>
        <div style="font-size:0.68rem;color:#888;margin:-0.3rem 0 0.5rem;">${lista}</div>`;
    }
    return `
    <div class="order-summary__row">
      <span class="order-summary__item-name">${item.nombre}${item.variante_nombre ? ' — ' + item.variante_nombre : ''} <span class="order-summary__item-qty">x${item.cantidad}</span></span>
      <span>${fmt(item.precio * item.cantidad)}</span>
    </div>
  `;
  }).join('');

  let envioDetalle = '';
  if (envio && envio.tipo_entrega === 'retiro') {
    envioDetalle = `<div style="font-size:0.7rem;color:#888;margin-bottom:0.4rem;white-space:pre-line;">Retiro en punto de encuentro${envio.info_especial ? ': ' + window.Envio._esc(envio.info_especial) : ''}</div>`;
  } else if (envio) {
    envioDetalle = `<div style="font-size:0.7rem;color:#888;margin-bottom:0.4rem;">${window.Envio.descripcion(envio)} (CP ${envio.cp})</div>`;
    if (envio.requiere_sucursal) {
      envioDetalle += `<div style="font-size:0.7rem;color:#888;margin-bottom:0.4rem;">${
        envio.sucursal_nombre ? 'Retiro en: ' + window.Envio._esc(envio.sucursal_nombre) : 'Falta elegir la sucursal.'
      }</div>`;
    }
  }

  container.innerHTML = `
    <div class="order-summary">
      <div class="order-summary__title">RESUMEN DEL PEDIDO</div>
      ${itemsHTML}
      <div class="order-summary__divider"></div>
      <div class="order-summary__row">
        <span>Subtotal</span>
        <span>${fmt(subtotal)}</span>
      </div>
      <div class="order-summary__row" style="padding:0.5rem 0;">
        <span>Envío</span>
        <span>${envio ? (envio.bonificado ? 'Gratis' : fmt(envioTotal)) : 'Completá el CP'}</span>
      </div>
      ${envioDetalle}
      ${descuento > 0 ? `
      <div class="order-summary__row" style="color:#3a7a3a;padding:0.5rem 0;">
        <span>Descuento por transferencia (${PAGO_CONFIG.transferencia.descuento}%)</span>
        <span>&minus; ${fmt(descuento)}</span>
      </div>` : ''}
      <div class="order-summary__divider"></div>
      <div class="order-summary__row order-summary__row--total">
        <span>${envio ? 'Total' : 'Total estimado'}</span>
        <span>${fmt(total)}</span>
      </div>
      <div id="checkout-cuotas" class="order-summary__cuotas"></div>
      ${PAGO_CONFIG.transferencia.activa && PAGO_CONFIG.transferencia.descuento > 0 ? `
      <div style="font-size:0.7rem;color:#8B7966;margin-top:0.8rem;padding-top:0.8rem;border-top:1px solid #E0D5C0;">
        ${PAGO_CONFIG.transferencia.descuento}% de descuento en transferencia
      </div>` : ''}
    </div>
  `;

  // Cuotas sin interes para el total (solo con Mercado Pago).
  if (window.Pagos && metodoPagoElegido() === 'mercadopago' && PAGO_CONFIG.mp_mostrar_cuotas) {
    Pagos.renderCuotas(document.getElementById('checkout-cuotas'), total);
  }
}

// ---- Forma de pago ----
async function initFormaPago() {
  if (!window.Pagos) return;
  PAGO_CONFIG = await Pagos.config();

  // Mercado Pago solo se ofrece si el servidor tiene las credenciales.
  const labelMp = document.querySelector('#pago-opciones input[value="mercadopago"]')?.closest('.envio-opcion');
  if (labelMp) labelMp.hidden = !PAGO_CONFIG.mp_disponible;

  const btnPagar = document.getElementById('btn-pagar');
  if (!hayFormaDePago()) {
    // Sin ningun medio configurado no se toman pedidos: quedarian colgados
    // con el stock reservado y sin forma de pagarlos.
    const opciones = document.getElementById('pago-opciones');
    if (opciones) opciones.hidden = true;
    if (btnPagar) { btnPagar.disabled = true; btnPagar.textContent = 'COMPRAS NO DISPONIBLES'; }
    const wa = (typeof WHATSAPP_NUMERO !== 'undefined' && WHATSAPP_NUMERO)
      ? ` Podés coordinar tu compra por <a href="https://wa.me/${WHATSAPP_NUMERO}" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline;">WhatsApp</a>.`
      : '';
    opciones?.insertAdjacentHTML('afterend',
      `<p id="pago-sin-medios" style="margin:0.5rem 0 0;padding:0.9rem 1rem;background:#F6E4E1;color:#A32E24;border-radius:4px;font-size:0.85rem;line-height:1.6;">
         Por el momento no estamos tomando pedidos online.${wa}
       </p>`);
    return;
  }

  if (!PAGO_CONFIG.mp_disponible && PAGO_CONFIG.transferencia.activa) {
    // Queda la transferencia como unica opcion, ya seleccionada.
    const radioTransf = document.querySelector('#pago-opciones input[value="transferencia"]');
    if (radioTransf) {
      radioTransf.checked = true;
      document.querySelectorAll('#pago-opciones .envio-opcion').forEach(l => l.classList.remove('envio-opcion--activa'));
      radioTransf.closest('.envio-opcion')?.classList.add('envio-opcion--activa');
    }
    if (btnPagar) btnPagar.textContent = 'CONFIRMAR PEDIDO';
  }

  const t = PAGO_CONFIG.transferencia;
  const label = document.getElementById('pago-transferencia');
  if (label) {
    label.hidden = !t.activa;
    const desc = document.getElementById('pago-transf-descuento');
    if (desc) desc.textContent = t.descuento > 0 ? `${t.descuento}% OFF` : '';
    const det = document.getElementById('pago-transf-detalle');
    if (det) det.textContent = t.descuento > 0
      ? `Un solo pago con ${t.descuento}% de descuento sobre los productos. Te mostramos los datos al confirmar.`
      : 'Un solo pago. Te mostramos los datos al confirmar.';
  }

  const mpDet = document.getElementById('pago-mp-detalle');
  if (mpDet && PAGO_CONFIG.mp_mostrar_cuotas) {
    const subtotal = window.Carrito ? window.Carrito.getTotal() : 0;
    const texto = await Pagos.textoSinInteres(subtotal);
    mpDet.textContent = texto
      ? `Tarjeta de crédito (${texto.replace(/ de .*$/, '')}), débito o dinero en cuenta.`
      : 'Tarjeta de crédito, débito o dinero en cuenta.';
  }

  document.querySelectorAll('input[name="metodo-pago"]').forEach(input => {
    input.addEventListener('change', () => {
      document.querySelectorAll('#pago-opciones .envio-opcion').forEach(l => l.classList.remove('envio-opcion--activa'));
      input.closest('.envio-opcion').classList.add('envio-opcion--activa');
      const btn = document.getElementById('btn-pagar');
      if (btn) btn.textContent = input.value === 'transferencia' ? 'CONFIRMAR PEDIDO' : 'PAGAR CON MERCADOPAGO';
      renderCheckoutSummary();
    });
  });
}

// ---- Envío en el checkout ----

/** Muestra los campos de domicilio o el selector de sucursal segun la opcion. */
function aplicarTipoEntrega() {
  const envio     = getEnvioGuardado();
  const sucursal  = !!(envio && envio.requiere_sucursal);
  const retiro    = !!(envio && envio.tipo_entrega === 'retiro');
  const domicilio = ['grupo-calle', 'grupo-numero', 'grupo-piso', 'grupo-ciudad'];

  // Retiro en punto de encuentro: no hace falta domicilio ni sucursal, el
  // lugar ya lo puso el admin y se muestra en el resumen del pedido.
  domicilio.forEach(id => {
    const g = document.getElementById(id);
    if (!g) return;
    g.hidden = sucursal || retiro;
    g.querySelectorAll('input').forEach(i => {
      if (i.id === 'piso_depto') return;
      i.required = !sucursal && !retiro;
    });
  });

  const gs = document.getElementById('grupo-sucursal');
  if (gs) gs.hidden = !sucursal;
  const sel = document.getElementById('sucursal');
  if (sel) sel.required = sucursal;

  if (sucursal) cargarSucursales();
}

/** Config del retiro en punto de encuentro (activo + info), leida una vez. */
let RETIRO_CONFIG = { activo: false, info: '' };

/**
 * Primer paso del checkout: elegir entre envío a domicilio o retiro en
 * punto de encuentro. Si el retiro no está activado en el panel, la opción
 * ni se muestra y el checkout funciona como antes.
 */
async function initTipoEntrega() {
  try {
    const res  = await fetch(API_URL + '/configuracion');
    const json = await res.json();
    if (json.success && json.data) {
      RETIRO_CONFIG.activo = String(json.data.retiro_punto_encuentro_activo) === '1';
      RETIRO_CONFIG.info   = (json.data.retiro_punto_encuentro_info || '').trim();
    }
  } catch (e) { /* si falla, el checkout sigue con envío a domicilio nomas */ }

  const opcionRetiro = document.getElementById('opcion-tipo-retiro');
  if (RETIRO_CONFIG.activo && RETIRO_CONFIG.info !== '' && opcionRetiro) {
    opcionRetiro.hidden = false;
    const preview = document.getElementById('retiro-info-preview');
    if (preview) preview.textContent = RETIRO_CONFIG.info;
  }

  // Si ya habia un envio de tipo retiro guardado (volvio de una pestaña
  // anterior), refleja esa eleccion en el radio.
  const envioActual = getEnvioGuardado();
  if (envioActual && envioActual.tipo_entrega === 'retiro' && RETIRO_CONFIG.activo) {
    const radioRetiro = document.querySelector('input[name="tipo-entrega"][value="retiro"]');
    if (radioRetiro) radioRetiro.checked = true;
    marcarTipoEntregaActivo('retiro');
    mostrarBloqueSegunTipoEntrega('retiro');
  } else {
    mostrarBloqueSegunTipoEntrega('domicilio');
  }

  document.querySelectorAll('input[name="tipo-entrega"]').forEach(input => {
    input.addEventListener('change', () => {
      marcarTipoEntregaActivo(input.value);
      if (input.value === 'retiro') {
        seleccionarRetiroPunto();
      } else {
        volverAEnvioDomicilio();
      }
    });
  });
}

function marcarTipoEntregaActivo(valor) {
  document.querySelectorAll('#tipo-entrega-opciones .envio-opcion').forEach(l => l.classList.remove('envio-opcion--activa'));
  const input = document.querySelector(`input[name="tipo-entrega"][value="${valor}"]`);
  input?.closest('.envio-opcion')?.classList.add('envio-opcion--activa');
}

function mostrarBloqueSegunTipoEntrega(valor) {
  const bloqueEnvio  = document.getElementById('bloque-envio-domicilio');
  const bloqueRetiro = document.getElementById('bloque-retiro-info');
  if (bloqueEnvio)  bloqueEnvio.hidden  = valor === 'retiro';
  if (bloqueRetiro) bloqueRetiro.hidden = valor !== 'retiro';
}

/** El retiro es gratis y fijo: se guarda directo, sin pasar por /envios/cotizar. */
function seleccionarRetiroPunto() {
  mostrarBloqueSegunTipoEntrega('retiro');

  const textoInfo = document.getElementById('retiro-info-texto');
  if (textoInfo) textoInfo.textContent = RETIRO_CONFIG.info;

  window.Envio.set({
    cp: null,
    opcion_id:         'retiro_punto',
    proveedor:          'retiro',
    tipo_entrega:       'retiro',
    producto:           null,
    nombre:             'Retiro en punto de encuentro',
    precio:             0,
    precio_lista:       0,
    bonificado:         true,
    plazo:              'Coordinamos la fecha',
    requiere_sucursal:  false,
    sucursal_codigo:    null,
    sucursal_nombre:    null,
    provincia:          null,
    info_especial:      RETIRO_CONFIG.info,
  });

  renderCheckoutSummary();
}

/** Vuelve al flujo normal: si habia un CP cargado, se vuelve a cotizar. */
function volverAEnvioDomicilio() {
  mostrarBloqueSegunTipoEntrega('domicilio');

  const envioActual = getEnvioGuardado();
  if (envioActual && envioActual.tipo_entrega === 'retiro') {
    window.Envio.clear();
  }

  const cpInput = document.getElementById('cp');
  if (cpInput && cpInput.value.replace(/\D/g, '').length === 4) {
    cotizarEnCheckout();
  } else {
    renderCheckoutSummary();
  }
}

let _sucursalesProvincia = null;

async function cargarSucursales() {
  const sel       = document.getElementById('sucursal');
  const provincia = document.getElementById('provincia')?.value || '';
  if (!sel) return;

  if (!provincia) {
    sel.innerHTML = '<option value="">Elegí la provincia para ver las sucursales</option>';
    return;
  }
  if (_sucursalesProvincia === provincia && sel.options.length > 1) return;

  sel.innerHTML = '<option value="">Cargando sucursales...</option>';
  sel.disabled  = true;

  try {
    const res  = await fetch(API_URL + '/envios/sucursales?provincia=' + encodeURIComponent(provincia));
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'No se pudieron cargar las sucursales.');

    const envio = getEnvioGuardado() || {};
    sel.innerHTML = '<option value="">Elegí una sucursal</option>' + json.data.map(s => `
      <option value="${window.Envio._esc(s.codigo)}" data-nombre="${window.Envio._esc(s.nombre + ' — ' + s.direccion + ', ' + s.localidad)}"
        ${envio.sucursal_codigo === s.codigo ? 'selected' : ''}>
        ${window.Envio._esc(s.localidad ? s.localidad + ' · ' : '')}${window.Envio._esc(s.nombre)} (${window.Envio._esc(s.direccion)})
      </option>
    `).join('');
    _sucursalesProvincia = provincia;
  } catch (err) {
    sel.innerHTML = '<option value="">No se pudieron cargar las sucursales</option>';
    showToast(err.message, 'error');
  } finally {
    sel.disabled = false;
  }
}

function guardarSucursalElegida() {
  const sel   = document.getElementById('sucursal');
  const envio = getEnvioGuardado();
  if (!sel || !envio) return;
  const opt = sel.options[sel.selectedIndex];
  envio.sucursal_codigo = sel.value || null;
  envio.sucursal_nombre = sel.value ? (opt.dataset.nombre || opt.textContent.trim()) : null;
  envio.provincia       = document.getElementById('provincia')?.value || null;
  window.Envio.set(envio);
  renderCheckoutSummary();
}

async function cotizarEnCheckout() {
  const cpInput = document.getElementById('cp');
  const msg     = document.getElementById('envio-msg');
  const box     = document.getElementById('envio-opciones');
  const cp      = (cpInput?.value || '').trim();

  if (!/^\d{4}/.test(cp.replace(/\D/g, ''))) {
    if (msg) msg.textContent = 'Ingresá un código postal válido (4 dígitos).';
    return;
  }
  if (msg) msg.textContent = 'Calculando...';

  try {
    const r = await window.Envio.cotizar(cp);
    if (!r.ok) {
      window.Envio.clear();
      if (box) box.innerHTML = '';
      if (msg) msg.textContent = r.mensaje;
      renderCheckoutSummary();
      aplicarTipoEntrega();
      return;
    }

    const previa  = window.Envio.get();
    const elegida = r.opciones.find(o => previa && o.id === previa.opcion_id && previa.cp === r.cp) || r.opciones[0];
    window.Envio.elegir(r.cp, elegida);
    if (msg) msg.textContent = r.aviso || (r.opciones.length > 1 ? 'Elegí cómo querés recibirlo:' : '');

    if (box) {
      window.Envio.renderOpciones(box, r.opciones, elegida.id, opcion => {
        window.Envio.elegir(r.cp, opcion);
        renderCheckoutSummary();
        aplicarTipoEntrega();
      });
    }
    renderCheckoutSummary();
    aplicarTipoEntrega();
  } catch {
    if (msg) msg.textContent = 'Error al calcular el envío.';
  }
}

async function cargarProvincias() {
  const sel = document.getElementById('provincia');
  if (!sel) return;
  try {
    const res  = await fetch(API_URL + '/envios/provincias');
    const json = await res.json();
    PROVINCIAS = json.data || {};
  } catch {
    PROVINCIAS = {};
  }
  const envio = getEnvioGuardado() || {};
  sel.innerHTML = '<option value="">Elegí tu provincia</option>' +
    Object.entries(PROVINCIAS)
      .sort((a, b) => a[1].localeCompare(b[1]))
      .map(([cod, nombre]) => `<option value="${cod}" ${envio.provincia === cod ? 'selected' : ''}>${nombre}</option>`)
      .join('');
}

// ---- Form validation ----
function validateField(input) {
  const value = input.value.trim();
  const errorEl = input.parentElement.querySelector('.form-error') || input.closest('.form-group')?.querySelector('.form-error');

  let valid = true;
  let msg = '';

  if (input.required && value === '') {
    valid = false;
    msg = 'Este campo es obligatorio.';
  } else if (input.type === 'email' && value !== '') {
    const emailRgx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRgx.test(value)) {
      valid = false;
      msg = 'Ingresá un email válido.';
    }
  } else if (input.id === 'dni' && value !== '') {
    // DNI argentino: 7 u 8 digitos, escrito con o sin puntos.
    const digitos = value.replace(/\D+/g, '');
    if (digitos.length < 7 || digitos.length > 8) {
      valid = false;
      msg = 'El DNI tiene 7 u 8 números.';
    }
  } else if (input.id === 'telefono' && value !== '') {
    const telRgx = /^[\d\s\+\-\(\)]{7,}$/;
    if (!telRgx.test(value)) {
      valid = false;
      msg = 'Ingresá un teléfono válido.';
    }
  } else if (input.id === 'cp' && value !== '') {
    if (!/^\d{4}/.test(value.replace(/\D/g, ''))) {
      valid = false;
      msg = 'Ingresá un código postal válido.';
    }
  }

  if (valid) {
    input.classList.remove('error');
    if (errorEl) errorEl.textContent = '';
  } else {
    input.classList.add('error');
    if (errorEl) errorEl.textContent = msg;
  }

  return valid;
}

function validateForm(form) {
  const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
  let allValid = true;
  inputs.forEach(input => {
    if (input.closest('[hidden]')) return;
    if (!validateField(input)) allValid = false;
  });
  return allValid;
}

// ---- Submit checkout ----
async function submitCheckout(e) {
  e.preventDefault();

  const form = document.getElementById('checkout-form');
  if (!form) return;

  if (!validateForm(form)) {
    showToast('Por favor, completá todos los campos requeridos.', 'error');
    return;
  }

  const carrito = window.Carrito ? window.Carrito.get() : { items: [] };
  if (!carrito.items || carrito.items.length === 0) {
    showToast('Tu carrito está vacío.', 'error');
    return;
  }
  if (!hayFormaDePago()) {
    showToast('Por el momento no estamos tomando pedidos online.', 'error');
    return;
  }

  const envio    = getEnvioGuardado();
  const esRetiro = !!(envio && envio.tipo_entrega === 'retiro');
  const cp       = (document.getElementById('cp')?.value || '').replace(/\D/g, '').slice(0, 4);

  if (!envio) {
    showToast('Elegí cómo querés recibir tu pedido.', 'error');
    return;
  }
  // El retiro no depende de un CP: se valida aparte, sin recotizar.
  if (!esRetiro) {
    // El CP del formulario manda: si difiere del cotizado, se vuelve a cotizar.
    if (envio.cp !== cp) {
      showToast('Calculá el envío para tu código postal antes de pagar.', 'error');
      await cotizarEnCheckout();
      return;
    }
    if (envio.requiere_sucursal && !envio.sucursal_codigo) {
      showToast('Elegí la sucursal donde querés retirar el pedido.', 'error');
      document.getElementById('sucursal')?.focus();
      return;
    }
  }

  const btn = document.getElementById('btn-pagar');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Procesando...';
  }

  try {
  const provinciaCod = document.getElementById('provincia')?.value || '';
  const payload = {
    nombre:    document.getElementById('nombre')?.value.trim()    || '',
    apellido:  document.getElementById('apellido')?.value.trim()  || '',
    email:     document.getElementById('email')?.value.trim()     || '',
    telefono:  document.getElementById('telefono')?.value.trim()  || '',
    // Solo digitos: la mitad de la gente lo escribe con puntos.
    dni:       (document.getElementById('dni')?.value || '').replace(/\D+/g, ''),
    // Direccion estructurada (la pide Correo Argentino) + texto completo.
    calle:      (envio.requiere_sucursal || esRetiro) ? '' : (document.getElementById('calle')?.value.trim()      || ''),
    numero:     (envio.requiere_sucursal || esRetiro) ? '' : (document.getElementById('numero')?.value.trim()     || ''),
    piso_depto: (envio.requiere_sucursal || esRetiro) ? '' : (document.getElementById('piso_depto')?.value.trim() || ''),
    ciudad:     (envio.requiere_sucursal || esRetiro) ? '' : (document.getElementById('ciudad')?.value.trim()     || ''),
    provincia:  esRetiro ? '' : (PROVINCIAS[provinciaCod] || provinciaCod),
    direccion:  buildDireccion(envio),
    // Un combo manda el id del combo + que eligio en cada lugar; el servidor
    // lo abre en items normales y recalcula el precio (nunca confia en el
    // precio que vino del navegador). Ver PromoService::validarYExpandir.
    items:     carrito.items.map(item => item.esCombo ? {
      tipo:     'promo',
      promo_id: item.promo_id,
      picks:    (item.picks || []).map(p => ({ producto_id: p.producto_id, variante_id: p.variante_id || null })),
    } : {
      id:          item.id,
      // Opcion elegida: el servidor descuenta el stock de esta variante.
      variante_id: item.variante_id || null,
      cantidad:    item.cantidad,
    }),
    // Del envio solo va lo que eligio el cliente: el costo lo recalcula el servidor.
    envio: {
      cp,
      opcion_id:       envio.opcion_id,
      sucursal_codigo: envio.sucursal_codigo || '',
      sucursal_nombre: envio.sucursal_nombre || '',
    },
    // El descuento por transferencia tambien lo aplica el servidor.
    metodo_pago: metodoPagoElegido(),
  };

  // Combine nombre + apellido for the API
  payload.nombre = payload.nombre + ' ' + payload.apellido;

    const res = await fetch(API_URL + '/pedidos', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    const json = await res.json();

    if (!res.ok || !json.success) {
      throw new Error(json.message || 'Error al crear el pedido.');
    }

    // Vaciar carrito y limpiar el envio elegido
    if (window.Carrito) window.Carrito.vaciar();
    if (window.Envio)   window.Envio.clear();

    const pedidoCreado = json.data?.pedido || {};

    // Transferencia: no hay pasarela; se muestran los datos bancarios.
    if (json.data?.metodo_pago === 'transferencia') {
      showToast('Pedido creado. Te mostramos los datos para transferir.', 'success');
      setTimeout(() => {
        window.location.href = `${PAGES_BASE}/checkout-resultado?status=transferencia&pedido_id=${pedidoCreado.id}&monto=${encodeURIComponent(pedidoCreado.total || '')}`;
      }, 800);
      return;
    }

    // Redirect to MercadoPago (producción: init_point; sandbox solo como fallback)
    const initPoint = json.data?.init_point || json.data?.sandbox_init_point;
    if (initPoint) {
      showToast('Pedido creado. Redirigiendo a MercadoPago...', 'success');
      setTimeout(() => { window.location.href = initPoint; }, 1200);
    } else {
      // No MP configured — redirect to result page
      const pedidoId = json.data?.pedido?.id;
      showToast('Pedido creado correctamente.', 'success');
      setTimeout(() => {
        window.location.href = `${PAGES_BASE}/checkout-resultado?status=pending&pedido_id=${pedidoId}`;
      }, 1200);
    }

  } catch (err) {
    showToast(err.message || 'Error al procesar el pedido.', 'error');
    if (btn) {
      btn.disabled = false;
      btn.textContent = metodoPagoElegido() === 'transferencia' ? 'CONFIRMAR PEDIDO' : 'PAGAR CON MERCADOPAGO';
    }
  }
}

/** Texto completo de la direccion: "Calle 123, Piso 3, Ciudad, Provincia, CP". */
function buildDireccion(envio) {
  const provinciaCod = document.getElementById('provincia')?.value || '';
  const provincia    = PROVINCIAS[provinciaCod] || provinciaCod;
  const cp           = document.getElementById('cp')?.value.trim();

  if (envio && envio.tipo_entrega === 'retiro') {
    return 'Retiro en punto de encuentro' + (envio.info_especial ? ': ' + envio.info_especial : '');
  }

  if (envio && envio.requiere_sucursal) {
    return ['Retiro en sucursal Correo Argentino: ' + (envio.sucursal_nombre || envio.sucursal_codigo), provincia, cp]
      .filter(Boolean).join(', ');
  }

  const calleNumero = [document.getElementById('calle')?.value.trim(), document.getElementById('numero')?.value.trim()]
    .filter(Boolean).join(' ');
  const partes = [
    calleNumero,
    document.getElementById('piso_depto')?.value.trim(),
    document.getElementById('ciudad')?.value.trim(),
    provincia,
    cp,
  ].filter(Boolean);
  return partes.join(', ');
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  await initTipoEntrega();
  renderCheckoutSummary();

  // Un item que quedo invalido (opcion borrada, producto de baja) ya no
  // bloquea el pago con un error tecnico: se saca solo, se avisa, y el
  // resto del pedido sigue de largo.
  if (typeof corregirCarrito === 'function') {
    await corregirCarrito();
    renderCheckoutSummary();
  }

  const form = document.getElementById('checkout-form');
  if (form) {
    form.querySelectorAll('input, select').forEach(input => {
      input.addEventListener('blur', () => {
        validateField(input);
      });
    });
    form.addEventListener('submit', submitCheckout);
  }

  // Envío: CP precargado desde el carrito, y se puede recotizar aca mismo.
  const envio = getEnvioGuardado();
  const cpInput = document.getElementById('cp');
  if (cpInput && envio && envio.tipo_entrega !== 'retiro') cpInput.value = envio.cp;

  document.getElementById('btn-cotizar')?.addEventListener('click', cotizarEnCheckout);
  cpInput?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); cotizarEnCheckout(); } });
  cpInput?.addEventListener('change', () => {
    const actual = getEnvioGuardado();
    if (actual && actual.cp !== cpInput.value.replace(/\D/g, '').slice(0, 4)) cotizarEnCheckout();
  });

  await cargarProvincias();
  await initFormaPago();
  renderCheckoutSummary();
  document.getElementById('provincia')?.addEventListener('change', () => {
    _sucursalesProvincia = null;
    const actual = getEnvioGuardado();
    if (actual) { actual.provincia = document.getElementById('provincia').value || null; actual.sucursal_codigo = null; actual.sucursal_nombre = null; window.Envio.set(actual); }
    aplicarTipoEntrega();
    renderCheckoutSummary();
  });
  document.getElementById('sucursal')?.addEventListener('change', guardarSucursalElegida);

  // Con un envio ya elegido en el carrito se muestran de nuevo las opciones
  // (la cotizacion no sobrevive al cambio de pagina).
  if (envio && (window.Carrito?.get().items || []).length) {
    cotizarEnCheckout();
  } else {
    aplicarTipoEntrega();
  }

  // Redirect if cart empty (after a moment to let JS load)
  setTimeout(() => {
    const carrito = window.Carrito ? window.Carrito.get() : { items: [] };
    if (!carrito.items || carrito.items.length === 0) {
      if (document.getElementById('checkout-form')) {
        showToast('Tu carrito está vacío.', 'info');
        setTimeout(() => { window.location.href = PAGES_BASE + '/carrito'; }, 1500);
      }
    }
  }, 500);
});
