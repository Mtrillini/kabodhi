// ============================================================
// KABODHI — promo_picker.js
// "Armá tu combo": /promo?id=X — el cliente elige N productos (con su
// fragancia si corresponde) hasta completar el combo.
// ============================================================

const fmtP = n => '$ ' + Number(n).toLocaleString('es-AR');

function escP(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

let PROMO      = null;
let ELEGIBLES  = [];      // productos que se pueden elegir (mapeados, con variantes)
let PICKS      = [];      // uno por lugar del combo; null = todavia vacio

async function cargarPromo() {
  const cont = document.getElementById('promo-armador');
  const id   = parseInt(new URLSearchParams(location.search).get('id'));
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
      cantidad: parseInt(promo.cantidad_items) || 0,
      precio: parseFloat(promo.precio) || 0,
      producto_ids: (promo.producto_ids || []).map(x => parseInt(x)),
    };
    document.title = `${PROMO.nombre} — KABODHI`;

    const elegiblesSet = new Set(PROMO.producto_ids);
    ELEGIBLES = (Array.isArray(productos) ? productos : [])
      .filter(p => elegiblesSet.has(parseInt(p.id)))
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

    PICKS = new Array(PROMO.cantidad).fill(null);
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

function completo() {
  return PICKS.every(p => p !== null);
}

function render() {
  const cont = document.getElementById('promo-armador');
  const llenos = PICKS.filter(Boolean).length;

  cont.innerHTML = `
    <nav class="pdp__volver">
      <a href="${PAGES_BASE}/promos">← Volver a combos</a>
    </nav>

    <header class="promo-armador__header">
      <h1 class="promo-armador__nombre">${escP(PROMO.nombre)}</h1>
      ${PROMO.descripcion ? `<p class="promo-armador__desc">${escP(PROMO.descripcion)}</p>` : ''}
      <div class="promo-armador__precio">${fmtP(PROMO.precio)}</div>
      <p class="promo-armador__progreso">Elegiste ${llenos} de ${PROMO.cantidad} productos</p>
    </header>

    <div class="promo-armador__slots" id="promo-slots">
      ${PICKS.map((pick, i) => `
        <div class="promo-slot${pick ? ' is-lleno' : ''}" data-i="${i}">
          ${pick ? `
            <img class="promo-slot__img" src="${escP(pick.img)}" alt="">
            <div class="promo-slot__info">
              <span class="promo-slot__nombre">${escP(pick.nombre)}</span>
              ${pick.variante_nombre ? `<span class="promo-slot__variante">${escP(pick.variante_nombre)}</span>` : ''}
            </div>
            <button type="button" class="promo-slot__quitar" data-i="${i}" aria-label="Quitar">✕</button>
          ` : `
            <span class="promo-slot__num">${i + 1}</span>
            <span class="promo-slot__vacio">Elegí un producto</span>
          `}
        </div>
      `).join('')}
    </div>

    <section class="promo-armador__catalogo">
      <p class="promo-armador__catalogo-titulo">Elegí entre estos productos</p>
      <div class="promo-armador__grid" id="promo-grid">
        ${ELEGIBLES.map((p, i) => cardElegible(p, i)).join('')}
      </div>
    </section>

    <div class="promo-armador__footer">
      <button type="button" class="pdp__btn" id="promo-agregar" ${completo() ? '' : 'disabled'}>
        ${completo() ? `AGREGAR COMBO AL CARRITO — ${fmtP(PROMO.precio)}` : 'ELEGÍ TODOS LOS PRODUCTOS'}
      </button>
    </div>
  `;

  enlazarEventosPromo();
  if (window.observeReveals) window.observeReveals(cont);
}

function cardElegible(p, i) {
  const agotado = p.variantes.length ? p.variantes.every(v => v.stock === 0) : p.stock === 0;
  return `
    <div class="promo-prod${agotado ? ' is-agotado' : ''}" data-prod="${i}">
      <div class="promo-prod__img-wrap">
        ${p.img ? `<img src="${escP(p.img)}" alt="${escP(p.nombre)}" loading="lazy">` : ''}
      </div>
      <div class="promo-prod__nombre">${escP(p.nombre)}</div>
      <div class="promo-prod__precio-lista">${fmtP(p.precio)} por separado</div>
      ${agotado ? `<div class="promo-prod__sinstock">Sin stock</div>` : (
        p.variantes.length
          ? `<div class="promo-prod__variantes">
              ${p.variantes.map((v, vi) => `
                <button type="button" class="promo-prod__variante${v.stock === 0 ? ' is-agotada' : ''}"
                        data-prod="${i}" data-var="${vi}" ${v.stock === 0 ? 'disabled' : ''}>
                  ${escP(v.nombre)}
                </button>`).join('')}
            </div>`
          : `<button type="button" class="promo-prod__elegir" data-prod="${i}">+ Elegir</button>`
      )}
    </div>`;
}

function primerLugarVacio() {
  return PICKS.findIndex(p => p === null);
}

function agregarPick(prodIndex, varIndex) {
  const lugar = primerLugarVacio();
  if (lugar === -1) {
    window.showToast(`Ya elegiste los ${PROMO.cantidad} productos del combo. Sacá uno si querés cambiarlo.`, 'error');
    return;
  }
  const p = ELEGIBLES[prodIndex];
  if (!p) return;

  let variante = null;
  if (p.variantes.length) {
    variante = p.variantes[varIndex];
    if (!variante || variante.stock === 0) return;
  } else if (p.stock === 0) {
    return;
  }

  PICKS[lugar] = {
    producto_id: p.id,
    variante_id: variante ? variante.id : null,
    nombre: p.nombre,
    variante_nombre: variante ? variante.nombre : null,
    img: (variante && variante.img) || p.img,
  };
  render();
}

function quitarPick(i) {
  PICKS[i] = null;
  render();
}

function agregarComboAlCarrito() {
  if (!completo()) return;
  if (typeof window.Carrito === 'undefined' || typeof window.Carrito.agregarPromo !== 'function') {
    window.showToast('Error: módulo de carrito no disponible.', 'error');
    return;
  }

  window.Carrito.agregarPromo({
    promoId:     PROMO.id,
    promoNombre: PROMO.nombre,
    precio:      PROMO.precio,
    imagen_url:  PICKS[0]?.img || '',
    picks:       PICKS.map(p => ({
      producto_id: p.producto_id, variante_id: p.variante_id,
      nombre: p.nombre, variante_nombre: p.variante_nombre,
    })),
  });

  window.showToast(`"${PROMO.nombre}" agregado al carrito.`, 'success');
  PICKS = new Array(PROMO.cantidad).fill(null);
  render();
}

function enlazarEventosPromo() {
  document.querySelectorAll('.promo-slot__quitar').forEach(btn => {
    btn.addEventListener('click', () => quitarPick(parseInt(btn.dataset.i)));
  });
  document.querySelectorAll('.promo-prod__elegir').forEach(btn => {
    btn.addEventListener('click', () => agregarPick(parseInt(btn.dataset.prod), null));
  });
  document.querySelectorAll('.promo-prod__variante').forEach(btn => {
    btn.addEventListener('click', () => agregarPick(parseInt(btn.dataset.prod), parseInt(btn.dataset.var)));
  });
  document.getElementById('promo-agregar')?.addEventListener('click', agregarComboAlCarrito);
}

document.addEventListener('DOMContentLoaded', cargarPromo);
