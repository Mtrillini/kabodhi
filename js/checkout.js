// ============================================================
// KABODHI — checkout.js
// ============================================================

// ---- Envío helpers ----
function getEnvioGuardado() {
  return window.Envio ? window.Envio.get() : null;
}

/** Provincias con el codigo que usa Correo Argentino (se cargan de la API). */
let PROVINCIAS = {};

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
  const total      = subtotal + envioTotal;

  const itemsHTML = carrito.items.map(item => `
    <div class="order-summary__row">
      <span class="order-summary__item-name">${item.nombre} <span class="order-summary__item-qty">x${item.cantidad}</span></span>
      <span>${fmt(item.precio * item.cantidad)}</span>
    </div>
  `).join('');

  let envioDetalle = '';
  if (envio) {
    envioDetalle = `<div style="font-size:0.7rem;color:#888;margin-bottom:0.4rem;">${window.Envio.descripcion(envio)} (CP ${envio.cp})</div>`;
    if (envio.requiere_sucursal) {
      envioDetalle += `<div style="font-size:0.7rem;color:#888;margin-bottom:0.4rem;">${
        envio.sucursal_nombre ? 'Retiro en: ' + window.Envio._esc(envio.sucursal_nombre) : 'Falta elegir la sucursal.'
      }</div>`;
    }
  }

  container.innerHTML = `
    <div class="order-summary">
      <div class="order-summary__title">Tu pedido</div>
      ${itemsHTML}
      <div class="order-summary__divider"></div>
      <div class="order-summary__row">
        <span>Envío</span>
        <span>${envio ? (envio.bonificado ? 'Gratis' : fmt(envioTotal)) : 'Completá el CP'}</span>
      </div>
      ${envioDetalle}
      <div class="order-summary__divider"></div>
      <div class="order-summary__row order-summary__row--total">
        <span>${envio ? 'Total' : 'Total estimado'}</span>
        <span>${fmt(total)}</span>
      </div>
    </div>
  `;
}

// ---- Envío en el checkout ----

/** Muestra los campos de domicilio o el selector de sucursal segun la opcion. */
function aplicarTipoEntrega() {
  const envio     = getEnvioGuardado();
  const sucursal  = !!(envio && envio.requiere_sucursal);
  const domicilio = ['grupo-calle', 'grupo-numero', 'grupo-piso', 'grupo-ciudad'];

  domicilio.forEach(id => {
    const g = document.getElementById(id);
    if (!g) return;
    g.hidden = sucursal;
    g.querySelectorAll('input').forEach(i => {
      if (i.id === 'piso_depto') return;
      i.required = !sucursal;
    });
  });

  const gs = document.getElementById('grupo-sucursal');
  if (gs) gs.hidden = !sucursal;
  const sel = document.getElementById('sucursal');
  if (sel) sel.required = sucursal;

  if (sucursal) cargarSucursales();
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

  const envio = getEnvioGuardado();
  const cp    = (document.getElementById('cp')?.value || '').replace(/\D/g, '').slice(0, 4);

  // El CP del formulario manda: si difiere del cotizado, se vuelve a cotizar.
  if (!envio || envio.cp !== cp) {
    showToast('Calculá el envío para tu código postal antes de pagar.', 'error');
    await cotizarEnCheckout();
    return;
  }
  if (envio.requiere_sucursal && !envio.sucursal_codigo) {
    showToast('Elegí la sucursal donde querés retirar el pedido.', 'error');
    document.getElementById('sucursal')?.focus();
    return;
  }

  const btn = document.getElementById('btn-pagar');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Procesando...';
  }

  const provinciaCod = document.getElementById('provincia')?.value || '';
  const payload = {
    nombre:    document.getElementById('nombre')?.value.trim()    || '',
    apellido:  document.getElementById('apellido')?.value.trim()  || '',
    email:     document.getElementById('email')?.value.trim()     || '',
    telefono:  document.getElementById('telefono')?.value.trim()  || '',
    // Solo digitos: la mitad de la gente lo escribe con puntos.
    dni:       (document.getElementById('dni')?.value || '').replace(/\D+/g, ''),
    // Direccion estructurada (la pide Correo Argentino) + texto completo.
    calle:      envio.requiere_sucursal ? '' : (document.getElementById('calle')?.value.trim()      || ''),
    numero:     envio.requiere_sucursal ? '' : (document.getElementById('numero')?.value.trim()     || ''),
    piso_depto: envio.requiere_sucursal ? '' : (document.getElementById('piso_depto')?.value.trim() || ''),
    ciudad:     envio.requiere_sucursal ? '' : (document.getElementById('ciudad')?.value.trim()     || ''),
    provincia:  PROVINCIAS[provinciaCod] || provinciaCod,
    direccion:  buildDireccion(envio),
    items:     carrito.items.map(item => ({
      id:         item.id,
      cantidad:   item.cantidad,
    })),
    // Del envio solo va lo que eligio el cliente: el costo lo recalcula el servidor.
    envio: {
      cp,
      opcion_id:       envio.opcion_id,
      sucursal_codigo: envio.sucursal_codigo || '',
      sucursal_nombre: envio.sucursal_nombre || '',
    },
  };

  // Combine nombre + apellido for the API
  payload.nombre = payload.nombre + ' ' + payload.apellido;

  try {
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
      btn.textContent = 'Pagar con MercadoPago';
    }
  }
}

/** Texto completo de la direccion: "Calle 123, Piso 3, Ciudad, Provincia, CP". */
function buildDireccion(envio) {
  const provinciaCod = document.getElementById('provincia')?.value || '';
  const provincia    = PROVINCIAS[provinciaCod] || provinciaCod;
  const cp           = document.getElementById('cp')?.value.trim();

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
  renderCheckoutSummary();

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
  if (cpInput && envio) cpInput.value = envio.cp;

  document.getElementById('btn-cotizar')?.addEventListener('click', cotizarEnCheckout);
  cpInput?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); cotizarEnCheckout(); } });
  cpInput?.addEventListener('change', () => {
    const actual = getEnvioGuardado();
    if (actual && actual.cp !== cpInput.value.replace(/\D/g, '').slice(0, 4)) cotizarEnCheckout();
  });

  await cargarProvincias();
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
