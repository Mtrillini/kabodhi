// ============================================================
// KABODHI — promos_front.js
// Grilla de combos ("Armá tu combo") en /promos.
// ============================================================

const fmtPromo = n => '$ ' + Number(n).toLocaleString('es-AR');

function escTextoPromo(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function cargarPromos() {
  const cont = document.getElementById('promos-grid');
  if (!cont) return;

  try {
    const json   = await loadPromosData();
    const promos = (json.data || []).filter(p => p.activo === undefined || parseInt(p.activo) === 1);

    if (!promos.length) {
      cont.innerHTML = `
        <div class="empty-state">
          <div class="empty-state__title">Por ahora no hay combos activos</div>
          <p class="empty-state__text">Volvé pronto o mirá el resto de los productos.</p>
        </div>`;
      return;
    }

    // La imagen del combo: la que cargo el admin o, si no hay, la del
    // primer producto elegible (para no dejar la tarjeta vacia).
    const json2 = await loadProductosData();
    const productos = json2.data || [];
    const imgProducto = id => {
      const p = productos.find(x => parseInt(x.id) === parseInt(id));
      return p ? (p.imagen_url || (p.imagenes && p.imagenes[0] && p.imagenes[0].url) || '') : '';
    };

    cont.innerHTML = promos.map(p => {
      const imagen = p.imagen_url || imgProducto((p.producto_ids || [])[0]);
      return `
      <a class="promo-card reveal reveal--up" href="${PAGES_BASE}/promo?id=${p.id}">
        <div class="promo-card__img-wrap">
          ${imagen ? `<img src="${escTextoPromo(imagen)}" alt="${escTextoPromo(p.nombre)}" loading="lazy">` : ''}
        </div>
        <div class="promo-card__body">
          <div class="promo-card__nombre">${escTextoPromo(p.nombre)}</div>
          <div class="promo-card__elegir">Elegí ${p.cantidad_items} productos</div>
          ${p.descripcion ? `<div class="promo-card__desc">${escTextoPromo(p.descripcion).slice(0, 90)}</div>` : ''}
          <div class="promo-card__precio">${fmtPromo(p.precio)}</div>
          <span class="promo-card__btn">ARMAR COMBO →</span>
        </div>
      </a>`;
    }).join('');

    if (window.observeReveals) window.observeReveals(cont);
  } catch (e) {
    console.error('Error cargando combos:', e);
    cont.innerHTML = `<p class="promos-page__cargando">No pudimos cargar los combos. Probá de nuevo en un momento.</p>`;
  }
}

document.addEventListener('DOMContentLoaded', cargarPromos);
