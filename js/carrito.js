// ============================================================
// KABODHI — carrito.js
// Cart localStorage structure:
// { items: [{id, nombre, precio, imagen_url, cantidad, stock}], updatedAt }
// ============================================================

const CART_KEY = 'nuve_cart';

// ---- Helpers ----
function getCarrito() {
  try {
    const raw = localStorage.getItem(CART_KEY);
    if (!raw) return { items: [], updatedAt: null };
    const parsed = JSON.parse(raw);
    if (!parsed || !Array.isArray(parsed.items)) return { items: [], updatedAt: null };
    return parsed;
  } catch {
    return { items: [], updatedAt: null };
  }
}

function guardarCarrito(carrito) {
  carrito.updatedAt = new Date().toISOString();
  localStorage.setItem(CART_KEY, JSON.stringify(carrito));
  // Notify other components
  window.dispatchEvent(new Event('carrito-updated'));
  if (typeof window.updateCartBadge === 'function') {
    window.updateCartBadge();
  }
}

function agregarItem(producto, cantidad = 1) {
  if ((producto.stock ?? 999) === 0) {
    if (window.showToast) window.showToast('Este producto no tiene stock disponible.', 'error');
    return getCarrito();
  }
  const carrito = getCarrito();
  const existingIdx = carrito.items.findIndex(i => i.id === producto.id);

  if (existingIdx >= 0) {
    const nuevoQty = carrito.items[existingIdx].cantidad + cantidad;
    const maxStock = producto.stock ?? carrito.items[existingIdx].stock ?? 999;
    carrito.items[existingIdx].cantidad = Math.min(nuevoQty, maxStock);
  } else {
    carrito.items.push({
      id:         producto.id,
      nombre:     producto.nombre,
      marca:      producto.marca || '',
      tipo:       producto.tipo || '',
      precio:     parseFloat(producto.precio),
      imagen_url: producto.img || producto.imagen_url || '',
      cantidad:   Math.min(cantidad, producto.stock ?? 999),
      stock:      producto.stock ?? 999,
    });
  }

  guardarCarrito(carrito);
  return carrito;
}

function quitarItem(id) {
  const carrito = getCarrito();
  carrito.items = carrito.items.filter(i => i.id !== parseInt(id));
  guardarCarrito(carrito);
  return carrito;
}

function cambiarCantidad(id, delta) {
  const carrito = getCarrito();
  const idx = carrito.items.findIndex(i => i.id === parseInt(id));
  if (idx < 0) return carrito;

  const item = carrito.items[idx];
  const newQty = item.cantidad + delta;

  if (newQty <= 0) {
    carrito.items.splice(idx, 1);
  } else {
    const maxStock = item.stock ?? 999;
    carrito.items[idx].cantidad = Math.min(newQty, maxStock);
  }

  guardarCarrito(carrito);
  return carrito;
}

function vaciarCarrito() {
  guardarCarrito({ items: [], updatedAt: null });
}

function getTotal() {
  const carrito = getCarrito();
  return carrito.items.reduce((sum, item) => sum + (item.precio * item.cantidad), 0);
}

function getTotalItems() {
  const carrito = getCarrito();
  return carrito.items.reduce((sum, item) => sum + item.cantidad, 0);
}

// ---- Expose globally ----
window.Carrito = {
  get:           getCarrito,
  guardar:       guardarCarrito,
  agregar:       agregarItem,
  quitar:        quitarItem,
  cambiarQty:    cambiarCantidad,
  vaciar:        vaciarCarrito,
  getTotal:      getTotal,
  getTotalItems: getTotalItems,
};

// ============================================================
// Render cart page (carrito.html)
// ============================================================
function renderCarrito() {
  const container = document.getElementById('cart-items-container');
  const summaryContainer = document.getElementById('cart-summary');
  if (!container) return;

  const carrito = getCarrito();

  if (carrito.items.length === 0) {
    container.innerHTML = `
      <div class="cart-empty">
        <div class="cart-empty__title">Tu carrito está vacío</div>
        <p class="cart-empty__text">Explorá nuestros adaptógenos y encontrá tu equilibrio.</p>
        <a href="${PAGES_BASE}/productos" class="cart-empty__btn">VER PRODUCTOS</a>
      </div>
    `;
    if (summaryContainer) renderSummary(carrito);
    return;
  }

  const fmt = window.formatMoney || (v => '$ ' + v.toLocaleString('es-AR'));

  const itemsHTML = carrito.items.map(item => `
    <div class="cart-item" data-id="${item.id}">
      <div class="cart-item__img-wrap">
        <img
          class="cart-item__img"
          src="${item.imagen_url || ''}"
          alt="${item.nombre}"
        >
      </div>
      <div class="cart-item__info">
        <div class="cart-item__name">${item.nombre}</div>
        ${item.marca ? `<div class="cart-item__marca">${item.marca}</div>` : ''}
        ${item.tipo  ? `<div class="cart-item__tipo">${item.tipo}</div>`   : ''}
        <div class="cart-item__price">${fmt(item.precio)} c/u</div>
      </div>
      <div class="cart-item__right">
        <div class="qty-control">
          <button class="qty-control__btn" onclick="handleQtyChange(${item.id}, -1)" aria-label="Restar">−</button>
          <span class="qty-control__value">${item.cantidad}</span>
          <button class="qty-control__btn" onclick="handleQtyChange(${item.id}, 1)" aria-label="Sumar" ${item.cantidad >= (item.stock ?? 999) ? 'disabled style="opacity:0.4;cursor:not-allowed;"' : ''}>+</button>
        </div>
        <div class="cart-item__subtotal">${fmt(item.precio * item.cantidad)}</div>
        <button class="cart-item__remove" onclick="handleRemove(${item.id})">Eliminar</button>
      </div>
    </div>
  `).join('');

  container.innerHTML = `<div class="cart-items">${itemsHTML}</div>`;
  if (summaryContainer) renderSummary(carrito);
}

function getEnvioGuardado() {
  try { return JSON.parse(localStorage.getItem('nuve_envio')) || null; } catch { return null; }
}

function renderSummary(carrito) {
  const container = document.getElementById('cart-summary');
  if (!container) return;

  const subtotal = getTotal();
  const fmt      = window.formatMoney || (v => '$' + v);
  const isEmpty  = !carrito || carrito.items.length === 0;
  const envio    = getEnvioGuardado();
  const envioTotal = envio ? parseFloat(envio.precio) : 0;
  const total    = subtotal + envioTotal;

  container.innerHTML = `
    <div class="order-summary">
      <div class="order-summary__title">Resumen del pedido</div>
      <div class="order-summary__row">
        <span>Subtotal</span>
        <span>${fmt(subtotal)}</span>
      </div>
      <div class="order-summary__divider"></div>

      <div style="margin-bottom:0.8rem;">
        <div class="order-summary__row" style="margin-bottom:0.4rem;">
          <span>Envío</span>
          <span>${envio ? fmt(envioTotal) : '—'}</span>
        </div>
        ${envio ? `<div style="font-size:0.7rem;color:#888;margin-bottom:0.5rem;">${envio.descripcion} (CP ${envio.cp})</div>` : ''}
        <div style="display:flex;gap:0.5rem;align-items:center;">
          <input
            type="text"
            id="cp-envio-input"
            placeholder="Tu código postal"
            maxlength="8"
            value="${envio ? envio.cp : ''}"
            style="flex:1;padding:0.45rem 0.6rem;border:1px solid #d6c6ad;border-radius:3px;font-family:inherit;font-size:0.75rem;outline:none;"
          >
          <button
            onclick="calcularEnvioCarrito()"
            style="padding:0.45rem 0.8rem;background:#1C3A4F;color:#EFE9DC;border:none;border-radius:3px;font-family:inherit;font-size:0.72rem;font-weight:600;letter-spacing:1px;cursor:pointer;white-space:nowrap;"
          >Calcular</button>
          ${envio ? `
          <button
            onclick="limpiarEnvioCarrito()"
            title="Limpiar código postal"
            style="padding:0.45rem 0.6rem;background:transparent;color:#888;border:1px solid #d6c6ad;border-radius:3px;font-family:inherit;font-size:0.72rem;cursor:pointer;white-space:nowrap;"
          >Limpiar</button>
          ` : ''}
        </div>
        <div id="envio-msg" style="font-size:0.72rem;color:#888;margin-top:0.3rem;min-height:1rem;"></div>
      </div>

      <div class="order-summary__divider"></div>
      <div class="order-summary__row order-summary__row--total">
        <span>${envio ? 'Total' : 'Total estimado'}</span>
        <span>${fmt(total)}</span>
      </div>
      <div class="order-summary__actions">
        ${IS_STATIC
          ? `<button onclick="pedirPorWhatsApp()" class="order-summary__btn-primary${isEmpty ? ' disabled' : ''}">
               PEDIR POR WHATSAPP
             </button>`
          : `<a href="${PAGES_BASE}/checkout" class="order-summary__btn-primary${isEmpty ? ' disabled' : ''}">
               PROCEDER AL PAGO
             </a>`}
        <a href="${PAGES_BASE}/productos" class="order-summary__btn-secondary">
          SEGUIR COMPRANDO
        </a>
        ${!isEmpty ? `<button onclick="handleVaciar()" class="order-summary__btn-vaciar">Vaciar carrito</button>` : ''}
      </div>
    </div>
  `;

  // Allow Enter key in CP input
  const cpInput = document.getElementById('cp-envio-input');
  if (cpInput) cpInput.addEventListener('keydown', e => { if (e.key === 'Enter') calcularEnvioCarrito(); });
}

async function calcularEnvioCarrito() {
  const cp  = (document.getElementById('cp-envio-input')?.value || '').trim();
  const msg = document.getElementById('envio-msg');

  if (IS_STATIC) {
    if (msg) msg.textContent = 'El costo de envío lo coordinamos por WhatsApp al confirmar tu pedido.';
    return;
  }

  if (!cp || !/^\d{4,}$/.test(cp)) {
    if (msg) msg.textContent = 'Ingresá un código postal válido (mínimo 4 dígitos).';
    return;
  }

  if (msg) msg.textContent = 'Calculando...';

  try {
    const res  = await fetch(API_URL + '/envios/calcular?cp=' + encodeURIComponent(cp));
    const json = await res.json();

    if (!json.success || !json.data) {
      localStorage.removeItem('nuve_envio');
      if (msg) msg.textContent = 'No hay tarifas para ese código postal.';
      renderSummary(getCarrito());
      return;
    }

    const tarifa = json.data;
    localStorage.setItem('nuve_envio', JSON.stringify({ cp, precio: tarifa.precio, descripcion: tarifa.descripcion }));
    renderSummary(getCarrito());
    if (window.showToast) showToast(`Envío: ${(window.formatMoney || (v => '$' + v))(tarifa.precio)}`, 'success');
  } catch {
    if (msg) msg.textContent = 'Error al calcular el envío.';
  }
}
window.calcularEnvioCarrito = calcularEnvioCarrito;

function limpiarEnvioCarrito() {
  localStorage.removeItem('nuve_envio');
  renderSummary(getCarrito());
}
window.limpiarEnvioCarrito = limpiarEnvioCarrito;

// ---- Pedido por WhatsApp (modo estatico) ----
window.pedirPorWhatsApp = function () {
  const carrito = getCarrito();
  if (!carrito || !carrito.items.length) return;
  const fmt = window.formatMoney || (v => '$' + v);
  const lineas = carrito.items.map(it => `• ${it.cantidad} x ${it.nombre} — ${fmt(it.precio * it.cantidad)}`);
  const texto =
    '¡Hola KABODHI! Quiero hacer este pedido:\n\n' +
    lineas.join('\n') +
    `\n\nTotal: ${fmt(getTotal())}\n\n¿Me pasan cómo seguir? ¡Gracias!`;
  window.open('https://wa.me/' + WHATSAPP_NUMERO + '?text=' + encodeURIComponent(texto), '_blank');
};

// ---- Event handlers ----
function handleQtyChange(id, delta) {
  cambiarCantidad(id, delta);
  renderCarrito();
}

function handleRemove(id) {
  quitarItem(id);
  renderCarrito();
  showToast('Producto eliminado del carrito.', 'info');
}

function handleVaciar() {
  if (confirm('¿Vaciar el carrito?')) {
    vaciarCarrito();
    renderCarrito();
    showToast('Carrito vaciado.', 'info');
  }
}

// ---- updateCounterBadge ----
function updateCounterBadge() {
  const badges = document.querySelectorAll('.cart-badge');
  const total = getTotalItems();
  badges.forEach(badge => {
    if (total > 0) {
      badge.textContent = total > 99 ? '99+' : total;
      badge.style.display = 'flex';
    } else {
      badge.style.display = 'none';
    }
  });
}

// ---- Init on carrito.html ----
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('cart-items-container')) {
    renderCarrito();
  }
  updateCounterBadge();
  window.addEventListener('carrito-updated', updateCounterBadge);
});
