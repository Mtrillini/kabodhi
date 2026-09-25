// ============================================================
// KABODHI — carrito.js
// Cart localStorage structure:
// { items: [{key, id, variante_id, variante_nombre, nombre, precio,
//            imagen_url, cantidad, stock}], updatedAt }
//
// `key` identifica la linea: dos aromas del mismo producto son dos lineas
// distintas, asi que no alcanza con el id del producto.
// ============================================================

// La version va en el nombre: un carrito guardado antes de las fragancias
// tiene lineas sin opcion elegida, y al pagar el servidor las rechaza con
// "Elegí una opción para...". Cambiar la clave los deja atras de una.
const CART_KEY = 'kabodhi_cart_v2';

// ---- Helpers ----
try { localStorage.removeItem('nuve_cart'); } catch { /* sin localStorage, nada que limpiar */ }

function claveItem(productoId, varianteId) {
  return `${parseInt(productoId)}:${parseInt(varianteId) || 0}`;
}

function getCarrito() {
  try {
    const raw = localStorage.getItem(CART_KEY);
    if (!raw) return { items: [], updatedAt: null };
    const parsed = JSON.parse(raw);
    if (!parsed || !Array.isArray(parsed.items)) return { items: [], updatedAt: null };
    // Carritos guardados antes de las variantes: no tienen `key`.
    parsed.items.forEach(i => {
      if (!i.key) i.key = claveItem(i.id, i.variante_id);
    });
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
  const key = claveItem(producto.id, producto.variante_id);
  const existingIdx = carrito.items.findIndex(i => i.key === key);

  if (existingIdx >= 0) {
    const nuevoQty = carrito.items[existingIdx].cantidad + cantidad;
    const maxStock = producto.stock ?? carrito.items[existingIdx].stock ?? 999;
    carrito.items[existingIdx].cantidad = Math.min(nuevoQty, maxStock);
  } else {
    carrito.items.push({
      key,
      id:         producto.id,
      // Opcion elegida (aroma, tamano); null si el producto no tiene opciones.
      variante_id:     producto.variante_id     || null,
      variante_nombre: producto.variante_nombre || null,
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

// Las lineas se identifican por `key` (producto:variante). Se acepta tambien
// un id de producto suelto por compatibilidad con llamadas viejas.
function quitarItem(key) {
  const carrito = getCarrito();
  const k = String(key).includes(':') ? String(key) : claveItem(key, 0);
  carrito.items = carrito.items.filter(i => i.key !== k);
  guardarCarrito(carrito);
  return carrito;
}

function cambiarCantidad(key, delta) {
  const carrito = getCarrito();
  const k = String(key).includes(':') ? String(key) : claveItem(key, 0);
  const idx = carrito.items.findIndex(i => i.key === k);
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
  agregarPromo:  agregarPromo,
  quitar:        quitarItem,
  cambiarQty:    cambiarCantidad,
  vaciar:        vaciarCarrito,
  getTotal:      getTotal,
  getTotalItems: getTotalItems,
};

// ============================================================
// Combos ("Arma tu combo")
// ============================================================
// Cada vez que se completa un combo se agrega como una linea NUEVA e
// independiente (no se "suma" con un combo igual ya agregado): asi se puede
// sacar o repetir un combo sin afectar a los demas. No tiene stepper de
// cantidad, solo "Eliminar".
//
// { promoId, promoNombre, precio, imagen_url, picks:[{producto_id,
//   variante_id, nombre, variante_nombre}, ...] }
function agregarPromo(combo) {
  const carrito = getCarrito();
  carrito.items.push({
    key:             `promo:${combo.promoId}:${Date.now()}`,
    // OJO: no usar `tipo` aca — los items normales ya usan esa clave para el
    // objetivo del producto (enfoque/energia/...) y se pisarian.
    esCombo:         true,
    id:              null,
    promo_id:        combo.promoId,
    promo_nombre:    combo.promoNombre,
    precio:          parseFloat(combo.precio),
    imagen_url:      combo.imagen_url || '',
    cantidad:        1,                       // fijo: sin +/-, se agrega de nuevo si quiere otro
    picks:           combo.picks || [],
  });
  guardarCarrito(carrito);
  return carrito;
}

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

  const itemsHTML = carrito.items.map(item => item.esCombo ? filaCombo(item, fmt) : filaProducto(item, fmt)).join('');

  container.innerHTML = `<div class="cart-items">${itemsHTML}</div>`;
  if (summaryContainer) renderSummary(carrito);
}

function filaProducto(item, fmt) {
  return `
    <div class="cart-item" data-key="${item.key}">
      <div class="cart-item__img-wrap">
        <img
          class="cart-item__img"
          src="${item.imagen_url || ''}"
          alt="${item.nombre}"
        >
      </div>
      <div class="cart-item__info">
        <div class="cart-item__name">${item.nombre}</div>
        ${item.variante_nombre ? `<div class="cart-item__variante">${item.variante_nombre}</div>` : ''}
        ${item.marca ? `<div class="cart-item__marca">${item.marca}</div>` : ''}
        ${item.tipo  ? `<div class="cart-item__tipo">${item.tipo}</div>`   : ''}
        <div class="cart-item__price">${fmt(item.precio)} c/u</div>
      </div>
      <div class="cart-item__right">
        <div class="qty-control">
          <button class="qty-control__btn" onclick="handleQtyChange('${item.key}', -1)" aria-label="Restar">−</button>
          <span class="qty-control__value">${item.cantidad}</span>
          <button class="qty-control__btn" onclick="handleQtyChange('${item.key}', 1)" aria-label="Sumar" ${item.cantidad >= (item.stock ?? 999) ? 'disabled style="opacity:0.4;cursor:not-allowed;"' : ''}>+</button>
        </div>
        <div class="cart-item__subtotal">${fmt(item.precio * item.cantidad)}</div>
        <button class="cart-item__remove" onclick="handleRemove('${item.key}')">Eliminar</button>
      </div>
    </div>`;
}

/** Un combo no tiene +/- (el precio es fijo para el paquete completo) ni
 *  precio "c/u": se agrega o se saca entero. */
function filaCombo(item, fmt) {
  const picks = (item.picks || []).map(p =>
    p.nombre + (p.variante_nombre ? ` — ${p.variante_nombre}` : '')
  );
  return `
    <div class="cart-item cart-item--combo" data-key="${item.key}">
      <div class="cart-item__img-wrap">
        <img class="cart-item__img" src="${item.imagen_url || ''}" alt="${item.promo_nombre}">
      </div>
      <div class="cart-item__info">
        <div class="cart-item__name">${item.promo_nombre}</div>
        <ul class="cart-item__combo-lista">
          ${picks.map(p => `<li>${p}</li>`).join('')}
        </ul>
      </div>
      <div class="cart-item__right">
        <div class="cart-item__subtotal">${fmt(item.precio)}</div>
        <button class="cart-item__remove" onclick="handleRemove('${item.key}')">Eliminar</button>
      </div>
    </div>`;
}

// La eleccion de envio la maneja js/envio.js (solo se carga en carrito y checkout).
function getEnvioGuardado() {
  return window.Envio ? window.Envio.get() : null;
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
  const cotizacion = window.Envio ? window.Envio._cotizacion : null;

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
          <span>${envio ? (envio.bonificado ? 'Gratis' : fmt(envioTotal)) : '—'}</span>
        </div>
        ${envio && !cotizacion ? `<div style="font-size:0.7rem;color:#888;margin-bottom:0.5rem;">${window.Envio.descripcion(envio)} (CP ${envio.cp})</div>` : ''}
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
          <input
            type="text"
            id="cp-envio-input"
            placeholder="Tu código postal"
            maxlength="8"
            inputmode="numeric"
            value="${envio ? envio.cp : ''}"
            style="flex:1 1 120px;min-width:0;padding:0.75rem 0.9rem;border:1px solid #d6c6ad;border-radius:999px;font-family:inherit;font-size:0.85rem;min-height:44px;outline:none;"
          >
          <button
            onclick="calcularEnvioCarrito()"
            style="flex:0 0 auto;padding:0.75rem 1.1rem;background:#1C3A4F;color:#F5F1E8;border:1px solid #1C3A4F;border-radius:999px;font-family:inherit;font-size:0.78rem;font-weight:600;letter-spacing:1px;min-height:44px;cursor:pointer;white-space:nowrap;"
          >Calcular</button>
          ${envio ? `
          <button
            onclick="limpiarEnvioCarrito()"
            title="Limpiar código postal"
            style="flex:0 0 auto;padding:0.75rem 1rem;background:transparent;color:#888;border:1px solid #d6c6ad;border-radius:999px;font-family:inherit;font-size:0.78rem;min-height:44px;cursor:pointer;white-space:nowrap;"
          >Limpiar</button>
          ` : ''}
        </div>
        <div id="envio-msg" style="font-size:0.72rem;color:#888;margin-top:0.3rem;min-height:1rem;"></div>
        <div id="envio-opciones" class="envio-opciones"></div>
      </div>

      <div class="order-summary__divider"></div>
      <div class="order-summary__row order-summary__row--total">
        <span>${envio ? 'Total' : 'Total estimado'}</span>
        <span>${fmt(total)}</span>
      </div>
      <div id="cart-cuotas" class="order-summary__cuotas"></div>
      <div id="cart-descuento-transferencia"></div>
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

  // Cuotas sin interes de Mercado Pago para el total.
  if (window.Pagos && !isEmpty && !IS_STATIC) Pagos.renderCuotas(document.getElementById('cart-cuotas'), total);

  // Aviso del descuento por transferencia (mismo texto que en el checkout).
  if (window.Pagos && !isEmpty && !IS_STATIC) {
    Pagos.config().then(cfg => {
      const el = document.getElementById('cart-descuento-transferencia');
      if (!el) return;
      if (cfg.transferencia.activa && cfg.transferencia.descuento > 0) {
        el.innerHTML = `<div style="font-size:0.7rem;color:#8B7966;margin-top:0.8rem;padding-top:0.8rem;border-top:1px solid #E0D5C0;">
          <strong style="color:#1C3A4F;">${cfg.transferencia.descuento}% de descuento</strong> en transferencia
        </div>`;
      }
    });
  }

  // Allow Enter key in CP input
  const cpInput = document.getElementById('cp-envio-input');
  if (cpInput) cpInput.addEventListener('keydown', e => { if (e.key === 'Enter') calcularEnvioCarrito(); });

  // Si en esta pagina ya se cotizo, se vuelven a mostrar las opciones.
  if (cotizacion && window.Envio) {
    const box = document.getElementById('envio-opciones');
    if (box) {
      window.Envio.renderOpciones(box, cotizacion.opciones, envio?.opcion_id, opcion => {
        window.Envio.elegir(cotizacion.cp, opcion);
        renderSummary(getCarrito());
      });
    }
  }
}

async function calcularEnvioCarrito() {
  const cp  = (document.getElementById('cp-envio-input')?.value || '').trim();
  const msg = document.getElementById('envio-msg');

  if (IS_STATIC || !window.Envio) {
    if (msg) msg.textContent = 'El costo de envío lo coordinamos por WhatsApp al confirmar tu pedido.';
    return;
  }

  if (!cp || !/^\d{4,}$/.test(cp.replace(/\D/g, ''))) {
    if (msg) msg.textContent = 'Ingresá un código postal válido (4 dígitos).';
    return;
  }

  if (msg) msg.textContent = 'Calculando...';

  try {
    const r = await window.Envio.cotizar(cp);

    if (!r.ok) {
      window.Envio.clear();
      renderSummary(getCarrito());
      const m = document.getElementById('envio-msg');
      if (m) m.textContent = r.mensaje;
      return;
    }

    // Se preselecciona la mas barata a domicilio (o la unica), pero el
    // cliente puede cambiarla; el checkout respeta lo elegido.
    const previa  = window.Envio.get();
    const elegida = r.opciones.find(o => previa && o.id === previa.opcion_id && previa.cp === r.cp) || r.opciones[0];
    window.Envio.elegir(r.cp, elegida);

    renderSummary(getCarrito());
    const m = document.getElementById('envio-msg');
    if (m) m.textContent = r.aviso || (r.opciones.length > 1 ? 'Elegí cómo querés recibirlo:' : '');
  } catch {
    if (msg) msg.textContent = 'Error al calcular el envío.';
  }
}
window.calcularEnvioCarrito = calcularEnvioCarrito;

function limpiarEnvioCarrito() {
  if (window.Envio) { window.Envio.clear(); window.Envio._cotizacion = null; }
  renderSummary(getCarrito());
}
window.limpiarEnvioCarrito = limpiarEnvioCarrito;

// ---- Pedido por WhatsApp (modo estatico) ----
window.pedirPorWhatsApp = async function () {
  const carrito = getCarrito();
  if (!carrito || !carrito.items.length) return;

  // El numero llega de la configuracion, que se carga async: si el visitante
  // hace clic antes de que resuelva, el link saldria sin destinatario.
  if (typeof configLista !== 'undefined') await configLista;

  if (!WHATSAPP_NUMERO) {
    if (window.showToast) showToast('No pudimos abrir WhatsApp. Escribinos por el formulario de contacto.', 'error');
    return;
  }

  const fmt = window.formatMoney || (v => '$' + v);
  const lineas = carrito.items.map(it => `• ${it.cantidad} x ${it.nombre}${it.variante_nombre ? " — " + it.variante_nombre : ""} — ${fmt(it.precio * it.cantidad)}`);
  const texto =
    '¡Hola KABODHI! Quiero hacer este pedido:\n\n' +
    lineas.join('\n') +
    `\n\nTotal: ${fmt(getTotal())}\n\n¿Me pasan cómo seguir? ¡Gracias!`;
  window.open('https://wa.me/' + WHATSAPP_NUMERO + '?text=' + encodeURIComponent(texto), '_blank');
};

// ---- Event handlers ----
function handleQtyChange(key, delta) {
  cambiarCantidad(key, delta);
  renderCarrito();
}

function handleRemove(key) {
  quitarItem(key);
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
document.addEventListener('DOMContentLoaded', async () => {
  if (document.getElementById('cart-items-container')) {
    renderCarrito();               // primero lo que hay, para no ver una pantalla vacia
    await corregirCarrito();
    renderCarrito();               // de nuevo, ya con lo invalido afuera

    // El costo depende del peso del bulto: si cambia el carrito con un envio
    // ya elegido, se vuelve a cotizar sin que el cliente tenga que pedirlo.
    window.addEventListener('carrito-updated', () => {
      const envio = getEnvioGuardado();
      if (envio && getCarrito().items.length && document.getElementById('cp-envio-input')) {
        calcularEnvioCarrito();
      } else if (envio && !getCarrito().items.length && window.Envio) {
        window.Envio.clear();
      }
    });
  }
  updateCounterBadge();
  window.addEventListener('carrito-updated', updateCounterBadge);
});

// ============================================================
// Autocorreccion del carrito
// ============================================================
// Un item puede quedar invalido por varias razones (se agrego antes de que
// el producto tuviera opciones, la opcion se borro, el producto se
// desactivo...). Antes, eso bloqueaba TODO el pago con un error tecnico.
// Ahora se valida contra el catalogo real al entrar al carrito o al
// checkout: lo que esta roto se saca solo, se avisa por que, y el resto de
// la compra sigue de largo.
async function corregirCarrito() {
  const carrito = getCarrito();
  if (!carrito.items.length) return carrito;

  let productos, promos;
  try {
    const [jsonProd, jsonPromo] = await Promise.all([
      loadProductosData(),
      typeof loadPromosData === 'function' ? loadPromosData() : Promise.resolve({ data: [] }),
    ]);
    productos = jsonProd.data || jsonProd;
    promos    = jsonPromo.data || jsonPromo;
  } catch {
    return carrito;   // sin catalogo no se puede validar; se deja como esta
  }
  if (!Array.isArray(productos)) return carrito;
  if (!Array.isArray(promos)) promos = [];

  const porId       = new Map(productos.map(p => [parseInt(p.id), p]));
  const promosPorId = new Map(promos.map(p => [parseInt(p.id), p]));
  const invalidos    = [];

  // Un producto+opcion sigue vigente (para lineas normales y para cada pick
  // adentro de un combo).
  function vigente(productoId, varianteId) {
    const p = porId.get(parseInt(productoId));
    if (!p || (p.activo !== undefined && parseInt(p.activo) !== 1)) return false;
    const variantes = p.variantes || [];
    if (!variantes.length) return true;
    return variantes.some(v => parseInt(v.id) === parseInt(varianteId) && parseInt(v.activo ?? 1) === 1);
  }

  const validos = carrito.items.filter(item => {
    if (item.esCombo) {
      const promo = promosPorId.get(parseInt(item.promo_id));
      const rota  = !promo
        || (promo.activo !== undefined && parseInt(promo.activo) !== 1)
        || (item.picks || []).length !== parseInt(promo.cantidad_items)
        || !(item.picks || []).every(pick =>
             (promo.producto_ids || []).map(Number).includes(parseInt(pick.producto_id))
             && vigente(pick.producto_id, pick.variante_id)
           );
      if (rota) {
        invalidos.push(item.promo_nombre || 'un combo');
        return false;
      }
      return true;
    }

    if (!vigente(item.id, item.variante_id)) {
      invalidos.push(item.variante_nombre ? `${item.nombre} (${item.variante_nombre})` : item.nombre);
      return false;
    }
    return true;
  });

  if (invalidos.length) {
    guardarCarrito({ ...carrito, items: validos });
    if (window.showToast) {
      const lista = invalidos.join(', ');
      window.showToast(
        `Sacamos del carrito ${invalidos.length > 1 ? 'estos productos' : 'este producto'} porque ya no está disponible así: ${lista}. Volvé a agregarlo si querés.`,
        'error'
      );
    }
  }

  return getCarrito();
}
window.corregirCarrito = corregirCarrito;
