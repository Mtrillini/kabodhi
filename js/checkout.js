// ============================================================
// KABODHI — checkout.js
// ============================================================

// ---- Envío helpers ----
function getEnvioGuardado() {
  try { return JSON.parse(localStorage.getItem('nuve_envio')) || null; } catch { return null; }
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
  const total      = subtotal + envioTotal;

  const itemsHTML = carrito.items.map(item => `
    <div class="order-summary__row">
      <span class="order-summary__item-name">${item.nombre} <span class="order-summary__item-qty">x${item.cantidad}</span></span>
      <span>${fmt(item.precio * item.cantidad)}</span>
    </div>
  `).join('');

  container.innerHTML = `
    <div class="order-summary">
      <div class="order-summary__title">Tu pedido</div>
      ${itemsHTML}
      <div class="order-summary__divider"></div>
      <div class="order-summary__row">
        <span>Envío</span>
        <span>${envio ? fmt(envioTotal) : 'Completá el CP'}</span>
      </div>
      ${envio ? `<div style="font-size:0.7rem;color:#888;margin-bottom:0.4rem;">${envio.descripcion} (CP ${envio.cp})</div>` : ''}
      <div class="order-summary__divider"></div>
      <div class="order-summary__row order-summary__row--total">
        <span>${envio ? 'Total' : 'Total estimado'}</span>
        <span>${fmt(total)}</span>
      </div>
    </div>
  `;
}

// ---- Form validation ----
function validateField(input) {
  const value = input.value.trim();
  const errorEl = input.parentElement.querySelector('.form-error');

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
    if (!/^\d{4,}$/.test(value)) {
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
  const inputs = form.querySelectorAll('input[required], textarea[required]');
  let allValid = true;
  inputs.forEach(input => {
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

  const btn = document.getElementById('btn-pagar');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Procesando...';
  }

  const envio = getEnvioGuardado();

  const payload = {
    nombre:    document.getElementById('nombre')?.value.trim()    || '',
    apellido:  document.getElementById('apellido')?.value.trim()  || '',
    email:     document.getElementById('email')?.value.trim()     || '',
    telefono:  document.getElementById('telefono')?.value.trim()  || '',
    // Solo digitos: la mitad de la gente lo escribe con puntos.
    dni:       (document.getElementById('dni')?.value || '').replace(/\D+/g, ''),
    direccion: buildDireccion(),
    items:     carrito.items.map(item => ({
      id:         item.id,
      cantidad:   item.cantidad,
    })),
    // Solo el CP: el costo de envio lo recalcula el servidor.
    envio_cp:           envio ? parseInt(envio.cp) : 0,
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

    // Vaciar carrito
    if (window.Carrito) window.Carrito.vaciar();

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

function buildDireccion() {
  const partes = [
    document.getElementById('direccion')?.value.trim(),
    document.getElementById('ciudad')?.value.trim(),
    document.getElementById('provincia')?.value.trim(),
    document.getElementById('cp')?.value.trim(),
  ].filter(Boolean);
  return partes.join(', ');
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', () => {
  renderCheckoutSummary();

  const form = document.getElementById('checkout-form');
  if (form) {
    form.querySelectorAll('input').forEach(input => {
      input.addEventListener('blur', () => {
        validateField(input);
      });
    });
    form.addEventListener('submit', submitCheckout);
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
