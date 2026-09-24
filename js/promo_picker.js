// ============================================================
// KABODHI — promo_picker.js
// /promo?id=X — el combo trae una lista fija de productos (la armó el
// admin). El cliente solo elige la fragancia/opción de cada producto,
// si ese producto tiene variantes.
// ============================================================

const fmtP = n => '$ ' + Number(n).toLocaleString('es-AR');

function escP(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

let PROMO      = null;
let PRODUCTOS  = [];      // productos fijos del combo (mapeados, con variantes)
let SELECCIONES = {};     // producto_id -> variante_id elegida (o null si no tiene variantes)

async function cargarPromo() {
  const id = parseInt(new URLSearchParams(location.search).get('id'));
  if (!id) { mostrarErrorPromo('No encontramos el combo que buscabas.'); return; }

  try {
    const [jsonPromos, jsonProductos] = await Promise.all([loadPromosData(), loadProductosData()]);
    const promos    = jsonPromos.data || jsonPromos;
    const productos = jsonProductos.data || jsonProductos;

    const promo = (Array.isArray(promos) ? promos : []).find(p => parseInt(p.id) === id);
    if (!promo || (promo.activo !== undefined && parseInt(promo.activo) !== 1)) {
      mostrarErrorPromo('Este combo ya no está disponible.');
      return;
    }

    PROMO = {
      id: parseInt(promo.id),
      nombre: promo.nombre || '',
      descripcion: promo.descripcion || '',
      precio: parseFloat(promo.precio) || 0,
      producto_ids: (promo.producto_ids || []).map(x => parseInt(x)),
    };
    document.title = `${PROMO.nombre} — KABODHI`;

    const todos = Array.isArray(productos) ? productos : [];
    PRODUCTOS = PROMO.producto_ids
      .map(id => todos.find(p => parseInt(p.id) === id))
      .filter(Boolean)
      .map(p => ({
        id: parseInt(p.id),
        nombre: p.nombre,
        precio: parseFloat(p.precio) || 0,
        img: p.imagen_url || (p.imagenes && p.imagenes[0] && p.imagenes[0].url) || '',
        stock: parseInt(p.stock_disponible ?? p.stock) || 0,
        variantes: (p.variantes || []).map(v => ({
          id: parseInt(v.id), nombre: v.nombre,
          img: v.imagen_url || '', stock: parseInt(v.stock_disponible ?? v.stock) || 0,
        })),
      }));

    if (PRODUCTOS.length !== PROMO.producto_ids.length) {
      mostrarErrorPromo('Este combo ya no está disponible.');
      return;
    }

    SELECCIONES = {};
    PRODUCTOS.forEach(p => { SELECCIONES[p.id] = null; });
    render();
  } catch (e) {
    console.error(e);
    mostrarErrorPromo('No pudimos cargar el combo. Probá de nuevo en un momento.');
  }
}

function mostrarErrorPromo(mensaje) {
  document.getElementById('promo-armador').innerHTML = `
    <div class="pdp__vacio">
      <p class="pdp__vacio-texto">${escP(mensaje)}</p>
      <a href="${PAGES_BASE}/promos" class="pdp__btn">VER OTROS COMBOS</a>
    </div>`;
}

function productoAgotado(p) {
  return p.variantes.length ? p.variantes.every(v => v.stock === 0) : p.stock === 0;
}

function listo() {
  return PRODUCTOS.every(p => productoAgotado(p) || !p.variantes.length || SELECCIONES[p.id] !== null);
}

function render() {
  const cont = document.getElementById('promo-armador');
  const hayAgotado = PRODUCTOS.some(productoAgotado);

  cont.innerHTML = `
    <nav class="pdp__volver">
      <a href="${PAGES_BASE}/promos">← Volver a combos</a>
    </nav>

    <header class="promo-armador__header">
      <h1 class="promo-armador__nombre">${escP(PROMO.nombre)}</h1>
      ${PROMO.descripcion ? `<p class="promo-armador__desc">${escP(PROMO.descripcion)}</p>` : ''}
      <div class="promo-armador__precio">${fmtP(PROMO.precio)}</div>
      <p class="promo-armador__progreso">Este combo incluye ${PRODUCTOS.length} productos</p>
    </header>

    <div class="promo-armador__items" id="promo-items">
      ${PRODUCTOS.map((p, i) => itemCombo(p, i)).join('')}
    </div>

    <div class="promo-armador__footer">
      ${hayAgotado
        ? `<p class="promo-armador__sinstock">Este combo no está disponible por el momento: hay productos sin stock.</p>`
        : `<button type="button" class="pdp__btn" id="promo-agregar" ${listo() ? '' : 'disabled'}>
            ${listo() ? `AGREGAR COMBO AL CARRITO — ${fmtP(PROMO.precio)}` : 'ELEGÍ LAS FRAGANCIAS FALTANTES'}
          </button>`}
    </div>
  `;

  enlazarEventosPromo();
  if (window.observeReveals) window.observeReveals(cont);
}

function itemCombo(p, i) {
  const agotado = productoAgotado(p);
  const varianteId = SELECCIONES[p.id];
  const imgMostrada = (varianteId && p.variantes.find(v => v.id === varianteId)?.img) || p.img;
  return `
    <div class="promo-item${agotado ? ' is-agotado' : ''}" data-prod="${i}">
      <div class="promo-item__img-wrap">
        ${imgMostrada ? `<img src="${escP(imgMostrada)}" alt="${escP(p.nombre)}" loading="lazy">` : ''}
      </div>
      <div class="promo-item__info">
        <div class="promo-item__nombre">${escP(p.nombre)}</div>
        <div class="promo-item__precio-lista">${fmtP(p.precio)} por separado</div>
        ${agotado ? `<div class="promo-item__sinstock">Sin stock</div>` : (
          p.variantes.length
            ? `<div class="promo-item__variantes">
                ${p.variantes.map(v => `
                  <button type="button" class="promo-item__variante${v.id === varianteId ? ' is-selected' : ''}${v.stock === 0 ? ' is-agotada' : ''}"
                          data-prod="${i}" data-var="${v.id}" ${v.stock === 0 ? 'disabled' : ''}>
                    ${escP(v.nombre)}
                  </button>`).join('')}
              </div>`
            : `<div class="promo-item__incluido">Incluido</div>`
        )}
      </div>
    </div>`;
}

function elegirVariante(prodIndex, varianteId) {
  const p = PRODUCTOS[prodIndex];
  if (!p) return;
  const variante = p.variantes.find(v => v.id === varianteId);
  if (!variante || variante.stock === 0) return;
  SELECCIONES[p.id] = varianteId;
  render();
}

function agregarComboAlCarrito() {
  if (!listo()) return;
  if (typeof window.Carrito === 'undefined' || typeof window.Carrito.agregarPromo !== 'function') {
    window.showToast('Error: módulo de carrito no disponible.', 'error');
    return;
  }

  const picks = PRODUCTOS.map(p => {
    const varianteId = SELECCIONES[p.id];
    const variante = varianteId ? p.variantes.find(v => v.id === varianteId) : null;
    return {
      producto_id: p.id,
      variante_id: variante ? variante.id : null,
      nombre: p.nombre,
      variante_nombre: variante ? variante.nombre : null,
      img: (variante && variante.img) || p.img,
    };
  });

  window.Carrito.agregarPromo({
    promoId:     PROMO.id,
    promoNombre: PROMO.nombre,
    precio:      PROMO.precio,
    imagen_url:  picks[0]?.img || '',
    picks:       picks.map(p => ({
      producto_id: p.producto_id, variante_id: p.variante_id,
      nombre: p.nombre, variante_nombre: p.variante_nombre,
    })),
  });

  window.showToast(`"${PROMO.nombre}" agregado al carrito.`, 'success');
}

function enlazarEventosPromo() {
  document.querySelectorAll('.promo-item__variante').forEach(btn => {
    btn.addEventListener('click', () => elegirVariante(parseInt(btn.dataset.prod), parseInt(btn.dataset.var)));
  });
  document.getElementById('promo-agregar')?.addEventListener('click', agregarComboAlCarrito);
}

document.addEventListener('DOMContentLoaded', cargarPromo);
