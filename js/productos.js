// ============================================================
// KABODHI — productos.js
// ============================================================

let PRODUCTOS = [];

const fmt        = n => '$ ' + n.toLocaleString('es-AR');
const capitalize = str => str ? str.charAt(0).toUpperCase() + str.slice(1) : '';

function mapProducto(p) {
  return {
    id:          p.id,
    marca:       p.marca || '',
    nombre:      p.nombre,
    tipo:        capitalize(p.tipo),
    precio:      parseFloat(p.precio),
    img:         p.imagen_url || '',
    imagenes:    (p.imagenes || []).map(i => i.url),
    descripcion: p.descripcion || '',
    nota:        p.nota_olfativa || '',
    stock:       parseInt(p.stock_disponible ?? p.stock) || 0,
    genero:      p.tipo,
    categoria_slug:   p.categoria_slug   || '',
    categoria_nombre: p.categoria_nombre || '',
    // Opciones del producto (aromas, tamanos). Vacio = se vende de una sola
    // forma y todo funciona como siempre.
    variantes:   (p.variantes || []).map(v => ({
      id:     parseInt(v.id),
      nombre: v.nombre,
      precio: v.precio != null ? parseFloat(v.precio) : null,
      img:    v.imagen_url || '',
      stock:  parseInt(v.stock_disponible ?? v.stock) || 0,
    })),
  };
}

// ---- Slider de imágenes dentro de cada card (flechas a los costados) ----
function escAttr(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;'); }

// Texto que se inserta como HTML (nombres cargados desde el panel).
function escTexto(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function cardSliderHTML(imgs, alt, fallback) {
  const list  = (imgs && imgs.length) ? imgs : (fallback ? [fallback] : []);
  const first = list[0] || '';
  // JSON dentro de atributo: lo guardamos con comillas simples escapadas
  const dataImgs = JSON.stringify(list).replace(/'/g, '&#39;');
  const arrows = list.length > 1 ? `
      <button type="button" class="card-slider__arrow card-slider__arrow--prev" aria-label="Imagen anterior">&#8592;</button>
      <button type="button" class="card-slider__arrow card-slider__arrow--next" aria-label="Imagen siguiente">&#8594;</button>` : '';
  const slides = list.map(src =>
    `<img class="card-slider__img" src="${escAttr(src)}" alt="${escAttr(alt)}" loading="lazy">`
  ).join('');
  return `<div class="card-slider" data-idx="0" data-imgs='${dataImgs}'>
      <div class="card-slider__track">${slides}</div>${arrows}
    </div>`;
}

// Navegación de las flechas (delegada, una sola vez para todas las cards).
// Se escucha en fase de CAPTURA para frenar el click ANTES de que la card
// dispare su handler de abrir el modal.
document.addEventListener('click', e => {
  const arrow = e.target.closest('.card-slider__arrow');
  if (!arrow) return;
  e.preventDefault();
  e.stopPropagation();              // no abrir el modal al tocar la flecha
  e.stopImmediatePropagation();
  const slider = arrow.closest('.card-slider');
  if (!slider) return;
  let imgs;
  try { imgs = JSON.parse(slider.dataset.imgs); } catch (_) { imgs = []; }
  if (imgs.length < 2) return;
  let idx = parseInt(slider.dataset.idx, 10) || 0;
  idx = arrow.classList.contains('card-slider__arrow--next')
    ? (idx + 1) % imgs.length
    : (idx - 1 + imgs.length) % imgs.length;
  slider.dataset.idx = idx;
  const track = slider.querySelector('.card-slider__track');
  if (track) track.style.transform = `translateX(-${idx * 100}%)`;
}, true);

// ---- Fetch all products ----
async function fetchAllProductos() {
  try {
    const json = await loadProductosData();
    if (!json.success) throw new Error(json.message);
    // Se respeta el flag "activo": asi el admin puede sacar un producto de
    // la vista sin borrarlo. El JSON horneado lo trae igual que la API.
    PRODUCTOS = (json.data || [])
      .filter(p => p.activo === undefined || parseInt(p.activo) === 1)
      .map(mapProducto);
  } catch (e) {
    console.error('Error cargando productos:', e);
    PRODUCTOS = [];
  }
}

// ---- Render cards (productos.html) ----
function renderProductos(lista) {
  const container = document.getElementById('productos-grid');
  if (!container) return;

  const count = document.getElementById('result-count');
  if (count) count.textContent = `${lista.length} producto${lista.length !== 1 ? 's' : ''}`;

  if (!lista.length) {
    container.innerHTML = `
      <div class="empty-state">
<div class="empty-state__title">No hay productos disponibles</div>
        <p class="empty-state__text">Pronto sumamos nuevos adaptógenos. Volvé pronto.</p>
      </div>`;
    return;
  }

  container.innerHTML = lista.map(p => {
    const imgs = (p.imagenes && p.imagenes.length) ? p.imagenes : (p.img ? [p.img] : []);
    return `
    <div class="nuve-card reveal reveal--up" onclick="${p.stock > 0 ? `abrirModal(${p.id})` : ''}">
      <div class="nuve-card__img-wrap">
        ${cardSliderHTML(imgs, p.nombre, p.img)}
      </div>
      <div class="nuve-card__body">
        <div class="nuve-card__nombre">${p.nombre}</div>
        <div class="nuve-card__marca">${p.marca}</div>
        <div class="nuve-card__tipo">${p.nota ? p.nota.slice(0, 60) : ''}</div>
        <div class="nuve-card__precio">${fmt(p.precio)}</div>
        <button
          class="nuve-card__btn${p.stock === 0 ? ' nuve-card__btn--agotado' : ''}"
          ${p.stock === 0 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : `onclick="event.stopPropagation(); abrirModal(${p.id})"`}>
          ${p.stock === 0 ? 'SIN STOCK' : 'AGREGAR AL CARRITO'}
        </button>
      </div>
    </div>
  `;
  }).join('');

  if (window.observeReveals) window.observeReveals(container);
}

// ---- Filtro + sort ----
let generoActivo = '';
let categoriaActiva = document.body.dataset.categoria || '';

// ---- Filtros por categoria ----
// Se arman con las categorias que realmente tienen productos publicados: si
// se agrega o se saca una desde el panel, la barra se acomoda sola.
function renderFiltrosCategoria() {
  const cont = document.getElementById('filtro-categorias');
  if (!cont) return;

  const categorias = new Map();
  PRODUCTOS.forEach(p => {
    if (p.categoria_slug) categorias.set(p.categoria_slug, p.categoria_nombre || p.categoria_slug);
  });

  // Con una sola categoria el filtro no filtra nada: no se muestra.
  if (categorias.size < 2) {
    cont.innerHTML = '';
    cont.style.display = 'none';
    return;
  }

  const ordenadas = [...categorias.entries()].sort((a, b) => a[1].localeCompare(b[1], 'es'));
  const opciones  = [['', 'Todos'], ...ordenadas];

  cont.style.display = '';
  cont.innerHTML = opciones.map(([slug, nombre]) => `
    <button type="button"
            class="cat-filtro${slug === categoriaActiva ? ' is-activo' : ''}"
            data-slug="${escAttr(slug)}">${escTexto(nombre)}</button>
  `).join('');

  cont.querySelectorAll('.cat-filtro').forEach(btn => {
    btn.addEventListener('click', () => seleccionarCategoria(btn.dataset.slug));
  });
}

function seleccionarCategoria(slug) {
  categoriaActiva = slug || '';

  // La URL acompaña al filtro: asi el link se puede compartir y es el mismo
  // formato que usan los botones de los banners (?categoria=velas).
  const url = new URL(window.location.href);
  if (categoriaActiva) url.searchParams.set('categoria', categoriaActiva);
  else                 url.searchParams.delete('categoria');
  history.replaceState(null, '', url);

  renderFiltrosCategoria();
  aplicarFiltros();
}

function aplicarFiltros() {
  const search = (document.getElementById('search-input')?.value || '').toLowerCase();
  const sort   = document.getElementById('sort-select')?.value || 'recent';

  let lista = PRODUCTOS.filter(p => {
    const matchSearch    = p.nombre.toLowerCase().includes(search);
    // generoActivo = objetivo/beneficio (enfoque, energia, equilibrio, defensas, bienestar)
    const matchGenero    = !generoActivo || p.genero === generoActivo;
    const matchCategoria = !categoriaActiva || p.categoria_slug === categoriaActiva;
    return matchSearch && matchGenero && matchCategoria;
  });

  if (sort === 'price-asc')  lista.sort((a, b) => a.precio - b.precio);
  if (sort === 'price-desc') lista.sort((a, b) => b.precio - a.precio);
  if (sort === 'name')       lista.sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));

  renderProductos(lista);
}

// ---- Modal ----
let modalProductoActual = null;
let modalQty = 1;
let modalImgs = [];
let modalImgIdx = 0;

function modalGoTo(i) {
  if (!modalImgs.length) return;
  modalImgIdx = (i + modalImgs.length) % modalImgs.length;
  const main = document.getElementById('modal-img');
  if (main && modalImgs[modalImgIdx]) main.src = modalImgs[modalImgIdx];
  document.querySelectorAll('#modal-thumbs img').forEach((t, ti) => {
    t.style.border = '2px solid ' + (ti === modalImgIdx ? '#1C3A4F' : 'transparent');
  });
}

function modalSlide(delta) { modalGoTo(modalImgIdx + delta); }
window.modalGoTo = modalGoTo;
window.modalSlide = modalSlide;

function abrirModal(id) {
  const p = PRODUCTOS.find(x => x.id === id);
  if (!p) return;
  modalProductoActual = p;
  modalQty = 1;

  const imgs = p.imagenes && p.imagenes.length ? p.imagenes : (p.img ? [p.img] : []);
  modalImgs = imgs;
  modalImgIdx = 0;
  const mainImg = document.getElementById('modal-img');
  // src="" hace que el navegador pida la propia pagina como si fuera imagen.
  // Si el producto no tiene foto, se quita el atributo en vez de vaciarlo.
  if (imgs[0]) mainImg.src = imgs[0];
  else mainImg.removeAttribute('src');
  mainImg.alt = p.nombre;

  // Flechas de navegación dentro del modal (se inyectan una vez por apertura)
  const imgWrap = document.querySelector('#producto-modal .prod-modal__img-wrap');
  if (imgWrap) {
    imgWrap.querySelectorAll('.prod-modal__arrow').forEach(a => a.remove());
    if (imgs.length > 1) {
      const prev = document.createElement('button');
      prev.type = 'button';
      prev.className = 'prod-modal__arrow prod-modal__arrow--prev';
      prev.setAttribute('aria-label', 'Imagen anterior');
      prev.innerHTML = '&#8592;';
      prev.onclick = () => modalSlide(-1);
      const next = document.createElement('button');
      next.type = 'button';
      next.className = 'prod-modal__arrow prod-modal__arrow--next';
      next.setAttribute('aria-label', 'Imagen siguiente');
      next.innerHTML = '&#8594;';
      next.onclick = () => modalSlide(1);
      imgWrap.appendChild(prev);
      imgWrap.appendChild(next);
    }
  }

  document.getElementById('modal-marca').textContent  = p.marca;
  document.getElementById('modal-nombre').textContent = p.nombre;
  document.getElementById('modal-tipo').textContent   = p.nota || '';
  document.getElementById('modal-desc').textContent   = p.descripcion;

  // Las opciones se arman antes del precio: si la elegida tiene precio propio,
  // es el que se muestra.
  renderVariantes(p);
  document.getElementById('modal-precio').textContent = fmt(precioElegido());
  if (window.Pagos) Pagos.renderCuotas(document.getElementById('modal-cuotas'), precioElegido());
  document.getElementById('modal-qty').textContent    = modalQty;

  // Thumbnail strip
  const thumbsEl = document.getElementById('modal-thumbs');
  if (thumbsEl) {
    if (imgs.length > 1) {
      thumbsEl.style.display = 'flex';
      thumbsEl.innerHTML = imgs.map((url, i) => `
        <img
          src="${url}"
          onclick="modalGoTo(${i})"
          style="width:52px;height:52px;object-fit:cover;border-radius:3px;cursor:pointer;border:2px solid ${i === 0 ? '#1C3A4F' : 'transparent'};transition:border 0.2s;"
        >
      `).join('');
    } else {
      thumbsEl.style.display = 'none';
      thumbsEl.innerHTML = '';
    }
  }

  const modal = document.getElementById('producto-modal');
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  requestAnimationFrame(() => {
    modal.querySelector('.prod-modal__box').classList.add('open');
  });
}

function cerrarModal() {
  const modal = document.getElementById('producto-modal');
  if (!modal || modal.style.display === 'none') return;
  const box = modal.querySelector('.prod-modal__box');
  box.classList.remove('open');
  box.addEventListener('transitionend', () => {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }, { once: true });
  modalProductoActual = null;
  modalVariante       = null;
}

function cambiarQty(delta) {
  const maxQty = stockElegido() || 1;
  modalQty = Math.max(1, Math.min(modalQty + delta, maxQty));
  document.getElementById('modal-qty').textContent = modalQty;
}

// ---- Opciones (aromas, tamanos) ----
// La opcion elegida manda sobre el producto: define stock, precio y foto.
let modalVariante = null;

function stockElegido() {
  if (!modalProductoActual) return 999;
  if (modalVariante) return modalVariante.stock;
  return modalProductoActual.stock || 0;
}

function precioElegido() {
  if (!modalProductoActual) return 0;
  if (modalVariante && modalVariante.precio != null) return modalVariante.precio;
  return modalProductoActual.precio;
}

function renderVariantes(p) {
  const cont = document.getElementById('modal-variantes');
  if (!cont) return;

  const variantes = p.variantes || [];
  if (!variantes.length) {
    modalVariante = null;
    cont.style.display = 'none';
    cont.innerHTML = '';
    return;
  }

  // Arranca elegida la primera con stock: una opcion menos para el cliente.
  modalVariante = variantes.find(v => v.stock > 0) || null;

  cont.style.display = 'block';
  cont.innerHTML = `
    <p class="prod-modal__variantes-titulo">Elegí una opción</p>
    <div class="prod-modal__variantes-lista">
      ${variantes.map((v, i) => `
        <button
          type="button"
          class="prod-modal__variante${v.id === modalVariante?.id ? ' is-activa' : ''}${v.stock === 0 ? ' is-agotada' : ''}"
          ${v.stock === 0 ? 'disabled' : `onclick="elegirVariante(${i})"`}
        >${escTexto(v.nombre)}${v.stock === 0 ? ' · sin stock' : ''}</button>
      `).join('')}
    </div>`;
}

function elegirVariante(index) {
  if (!modalProductoActual) return;
  const v = (modalProductoActual.variantes || [])[index];
  if (!v || v.stock === 0) return;

  modalVariante = v;
  modalQty = 1;

  // La foto de la opcion, si tiene, pasa a ser la principal.
  if (v.img) {
    const main = document.getElementById('modal-img');
    if (main) main.src = v.img;
  }

  document.getElementById('modal-qty').textContent    = modalQty;
  document.getElementById('modal-precio').textContent = fmt(precioElegido());
  if (window.Pagos) Pagos.renderCuotas(document.getElementById('modal-cuotas'), precioElegido());

  document.querySelectorAll('#modal-variantes .prod-modal__variante').forEach((b, i) => {
    b.classList.toggle('is-activa', i === index);
  });
}

function agregarDesdeModal() {
  if (!modalProductoActual) return;

  const variantes = modalProductoActual.variantes || [];
  if (variantes.length && !modalVariante) {
    window.showToast('Elegí una opción antes de agregar al carrito.', 'error');
    return;
  }
  if (stockElegido() === 0) {
    window.showToast('Esta opción no tiene stock disponible.', 'error');
    return;
  }
  if (typeof window.Carrito === 'undefined') {
    window.showToast('Error: módulo de carrito no disponible.', 'error');
    return;
  }

  const nombre = modalVariante
    ? `${modalProductoActual.nombre} — ${modalVariante.nombre}`
    : modalProductoActual.nombre;

  window.Carrito.agregar({
    ...modalProductoActual,
    precio:          precioElegido(),
    stock:           stockElegido(),
    img:             (modalVariante && modalVariante.img) || modalProductoActual.img,
    variante_id:     modalVariante ? modalVariante.id : null,
    variante_nombre: modalVariante ? modalVariante.nombre : null,
  }, modalQty);

  window.showToast(`"${nombre}" agregado al carrito.`, 'success');
  cerrarModal();
}

window.abrirModal        = abrirModal;
window.cerrarModal       = cerrarModal;
window.cambiarQty        = cambiarQty;
window.agregarDesdeModal = agregarDesdeModal;
window.elegirVariante    = elegirVariante;

// ---- Featured (homepage — "Más Vendidos") ----
window.loadFeaturedProductos = async function () {
  const section = document.getElementById('mas-vendidos');
  if (!section) return;

  try {
    const json = await loadProductosData();
    if (!json.success || !json.data) return;
    const destacados = json.data.filter(p => parseInt(p.destacado) === 1);
    if (!destacados.length) return;

    const lista = destacados.map((p, i) => ({ ...mapProducto(p), rank: i + 1 }));

    // Merge into PRODUCTOS so modal works on homepage
    lista.forEach(p => {
      if (!PRODUCTOS.find(x => x.id === p.id)) PRODUCTOS.push(p);
    });

    renderMasVendidos(section, lista);
  } catch (e) {
    console.error('Error cargando destacados:', e);
  }
};

function renderMasVendidos(section, lista) {
  section.className = 'mas-vendidos__section';
  section.innerHTML = '';

  // ---- Header centrado: título arriba, "VER TODOS" debajo ----
  const header = document.createElement('div');
  header.className = 'mv-header';

  const title = document.createElement('h2');
  title.className = 'mv-header__title';
  title.textContent = 'MÁS VENDIDOS';

  const verTodos = document.createElement('a');
  verTodos.className = 'mv-header__link';
  verTodos.textContent = 'VER TODOS LOS PRODUCTOS';
  verTodos.href = (typeof PAGES_BASE !== 'undefined' ? PAGES_BASE : '') + '/productos';

  header.appendChild(title);
  header.appendChild(verTodos);
  section.appendChild(header);

  // ---- Cards: mismo markup/diseño que la página de productos (.nuve-card) ----
  const grid = document.createElement('div');
  grid.className = 'productos-grid-nuve';

  grid.innerHTML = lista.map((p, i) => {
    const imgs = (p.imagenes && p.imagenes.length) ? p.imagenes : (p.img ? [p.img] : []);
    const delay = 'reveal--d' + Math.min(i + 1, 6);
    return `
    <div class="nuve-card reveal reveal--up ${delay}" onclick="${p.stock > 0 ? `abrirModal(${p.id})` : ''}">
      <span class="nuve-card__badge">#${p.rank}</span>
      <div class="nuve-card__img-wrap">
        ${cardSliderHTML(imgs, p.nombre, p.img)}
      </div>
      <div class="nuve-card__body">
        <div class="nuve-card__nombre">${p.nombre}</div>
        <div class="nuve-card__marca">${p.marca}</div>
        <div class="nuve-card__tipo">${p.nota ? p.nota.slice(0, 60) : ''}</div>
        <div class="nuve-card__precio">${fmt(p.precio)}</div>
        <button
          class="nuve-card__btn${p.stock === 0 ? ' nuve-card__btn--agotado' : ''}"
          ${p.stock === 0 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : `onclick="event.stopPropagation(); abrirModal(${p.id})"`}>
          ${p.stock === 0 ? 'SIN STOCK' : 'AGREGAR AL CARRITO'}
        </button>
      </div>
    </div>`;
  }).join('');

  section.appendChild(grid);
  if (window.observeReveals) window.observeReveals(section);
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  const params       = new URLSearchParams(window.location.search);
  const generoUrl    = params.get('genero') || '';
  const categoriaUrl = params.get('categoria') || '';
  const searchUrl    = params.get('search') || '';
  if (searchUrl) {
    const si = document.getElementById('search-input');
    if (si) si.value = searchUrl;
  }
  // Los botones de categoria ya no existen, pero ?genero= y ?categoria=
  // siguen sirviendo: los usan los links del navbar.
  if (generoUrl) {
    generoActivo = generoUrl;
  } else if (categoriaUrl) {
    categoriaActiva = categoriaUrl;
  }

  // Only fetch + render grid on productos.html
  if (document.getElementById('productos-grid')) {
    await fetchAllProductos();
    renderFiltrosCategoria();
    aplicarFiltros();

    document.getElementById('search-input')?.addEventListener('input', aplicarFiltros);
    document.getElementById('sort-select')?.addEventListener('change', aplicarFiltros);
  }

  document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
});
