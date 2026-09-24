// ============================================================
// KABODHI — producto.js
// Ficha de un producto: /producto?id=8
//
// Un producto con opciones (aromas, tamaños) se vende por opcion: cada una
// tiene su foto, su stock y, si hiciera falta, su precio. Por eso la ficha
// muestra las opciones abajo, con la miniatura de cada una, y al elegir una
// cambia la foto grande, el precio y el maximo que se puede llevar.
// ============================================================

const fmtPrecio = n => '$ ' + Number(n).toLocaleString('es-AR');

function escTxt(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

let PRODUCTO  = null;
let VARIANTE  = null;   // null = el producto no tiene opciones
let CANTIDAD  = 1;
let GALERIA   = [];     // fotos del producto (sin las de las opciones)
let GALERIA_I = 0;

// ---- Datos derivados de la opcion elegida ----
const stockActual  = () => VARIANTE ? VARIANTE.stock : PRODUCTO.stock;
const precioActual = () => (VARIANTE && VARIANTE.precio != null) ? VARIANTE.precio : PRODUCTO.precio;
const nombreActual = () => VARIANTE ? `${PRODUCTO.nombre} — ${VARIANTE.nombre}` : PRODUCTO.nombre;

function mapear(p) {
  return {
    id:          parseInt(p.id),
    nombre:      p.nombre || '',
    marca:       p.marca || '',
    nota:        p.nota_olfativa || '',
    descripcion: p.descripcion || '',
    precio:      parseFloat(p.precio) || 0,
    stock:       parseInt(p.stock_disponible ?? p.stock) || 0,
    img:         p.imagen_url || '',
    imagenes:    (p.imagenes || []).map(i => i.url).filter(Boolean),
    categoria:   p.categoria_nombre || '',
    variantes:   (p.variantes || []).map(v => ({
      id:     parseInt(v.id),
      nombre: v.nombre || '',
      precio: v.precio != null ? parseFloat(v.precio) : null,
      img:    v.imagen_url || '',
      stock:  parseInt(v.stock_disponible ?? v.stock) || 0,
    })),
  };
}

// ---- Carga ----
async function cargarProducto() {
  const cont = document.getElementById('pdp');
  const id   = parseInt(new URLSearchParams(location.search).get('id'));

  if (!id) { mostrarError('No encontramos el producto que buscabas.'); return; }

  try {
    const json = await loadProductosData();
    const fila = (json.data || []).find(p => parseInt(p.id) === id);
    if (!fila || (fila.activo !== undefined && parseInt(fila.activo) !== 1)) {
      mostrarError('Este producto ya no está disponible.');
      return;
    }

    PRODUCTO = mapear(fila);
    document.title = `${PRODUCTO.nombre} — KABODHI`;

    // Arranca elegida la primera opcion con stock.
    VARIANTE = PRODUCTO.variantes.find(v => v.stock > 0) || null;
    GALERIA  = PRODUCTO.imagenes.length ? PRODUCTO.imagenes : (PRODUCTO.img ? [PRODUCTO.img] : []);

    render();
  } catch (e) {
    console.error(e);
    mostrarError('No pudimos cargar el producto. Probá de nuevo en un momento.');
  }
}

function mostrarError(mensaje) {
  document.getElementById('pdp').innerHTML = `
    <div class="pdp__vacio">
      <p class="pdp__vacio-texto">${escTxt(mensaje)}</p>
      <a href="${PAGES_BASE}/productos" class="pdp__btn">VER TODOS LOS PRODUCTOS</a>
    </div>`;
}

// ---- Render ----
function render() {
  const p         = PRODUCTO;
  const agotado   = stockActual() === 0;
  const conOpciones = p.variantes.length > 0;

  document.getElementById('pdp').innerHTML = `
    <nav class="pdp__volver">
      <a href="${PAGES_BASE}/productos">← Volver a productos</a>
    </nav>

    <div class="pdp__grid">
      <div class="pdp__galeria">
        <div class="pdp__foto-wrap">
          <img id="pdp-foto" class="pdp__foto" src="${escTxt(fotoPrincipal())}" alt="${escTxt(nombreActual())}">
        </div>
        ${GALERIA.length > 1 ? `
          <div class="pdp__miniaturas" id="pdp-miniaturas">
            ${GALERIA.map((url, i) => `
              <button type="button" class="pdp__miniatura${i === GALERIA_I ? ' is-activa' : ''}" data-i="${i}">
                <img src="${escTxt(url)}" alt="">
              </button>`).join('')}
          </div>` : ''}
      </div>

      <div class="pdp__info">
        ${p.categoria ? `<p class="pdp__categoria">${escTxt(p.categoria)}</p>` : ''}
        <h1 class="pdp__nombre">${escTxt(p.nombre)}</h1>
        ${p.nota ? `<p class="pdp__nota">${escTxt(p.nota)}</p>` : ''}

        <div class="pdp__precio-wrap">
          <span class="pdp__precio" id="pdp-precio">${fmtPrecio(precioActual())}</span>
          <span class="pdp__cuotas" id="pdp-cuotas"></span>
        </div>

        ${p.descripcion ? `<p class="pdp__desc">${escTxt(p.descripcion)}</p>` : ''}

        ${conOpciones ? `
          <div class="pdp__opciones">
            <p class="pdp__opciones-titulo">Elegí tu fragancia</p>
            <div class="pdp__opciones-lista" id="pdp-opciones">
              ${p.variantes.map((v, i) => `
                <button type="button"
                        class="pdp__opcion${VARIANTE && v.id === VARIANTE.id ? ' is-activa' : ''}${v.stock === 0 ? ' is-agotada' : ''}"
                        data-i="${i}" ${v.stock === 0 ? 'disabled' : ''}>
                  <span class="pdp__opcion-foto">
                    <img src="${escTxt(v.img || fotoPrincipal())}" alt="">
                  </span>
                  <span class="pdp__opcion-nombre">${escTxt(v.nombre)}</span>
                  <span class="pdp__opcion-stock">${v.stock === 0 ? 'Sin stock' : v.stock + ' disponibles'}</span>
                </button>`).join('')}
            </div>
          </div>` : ''}

        <div class="pdp__compra">
          <div class="pdp__qty">
            <button type="button" id="pdp-menos" aria-label="Restar">−</button>
            <span id="pdp-qty">${CANTIDAD}</span>
            <button type="button" id="pdp-mas" aria-label="Sumar">+</button>
          </div>
          <button type="button" class="pdp__btn" id="pdp-agregar" ${agotado ? 'disabled' : ''}>
            ${agotado ? 'SIN STOCK' : 'AGREGAR AL CARRITO'}
          </button>
        </div>

        <p class="pdp__stock" id="pdp-stock">${textoStock()}</p>
      </div>
    </div>`;

  enlazarEventos();
  actualizarCuotas();
  if (window.observeReveals) window.observeReveals(document.getElementById('pdp'));
}

/** La foto de la opcion elegida manda sobre la galeria del producto. */
function fotoPrincipal() {
  if (VARIANTE && VARIANTE.img) return VARIANTE.img;
  return GALERIA[GALERIA_I] || PRODUCTO.img || '';
}

function textoStock() {
  const s = stockActual();
  if (s === 0)  return 'Sin stock por el momento.';
  if (s <= 3)   return `¡Últimas ${s} unidades!`;
  return `${s} unidades disponibles`;
}

function actualizarCuotas() {
  if (window.Pagos) Pagos.renderCuotas(document.getElementById('pdp-cuotas'), precioActual());
}

function enlazarEventos() {
  document.querySelectorAll('#pdp-miniaturas .pdp__miniatura').forEach(btn => {
    btn.addEventListener('click', () => {
      GALERIA_I = parseInt(btn.dataset.i);
      // Mirar la galeria del producto saca la foto de la opcion de encima.
      const foto = document.getElementById('pdp-foto');
      if (foto) foto.src = GALERIA[GALERIA_I];
      document.querySelectorAll('#pdp-miniaturas .pdp__miniatura')
        .forEach((b, i) => b.classList.toggle('is-activa', i === GALERIA_I));
    });
  });

  document.querySelectorAll('#pdp-opciones .pdp__opcion').forEach(btn => {
    btn.addEventListener('click', () => elegirOpcion(parseInt(btn.dataset.i)));
  });

  document.getElementById('pdp-menos')?.addEventListener('click', () => cambiarCantidad(-1));
  document.getElementById('pdp-mas')?.addEventListener('click',   () => cambiarCantidad(1));
  document.getElementById('pdp-agregar')?.addEventListener('click', agregarAlCarrito);
}

function elegirOpcion(i) {
  const v = PRODUCTO.variantes[i];
  if (!v || v.stock === 0) return;

  VARIANTE = v;
  CANTIDAD = 1;

  const foto = document.getElementById('pdp-foto');
  if (foto && v.img) foto.src = v.img;

  document.getElementById('pdp-precio').textContent = fmtPrecio(precioActual());
  document.getElementById('pdp-qty').textContent    = CANTIDAD;
  document.getElementById('pdp-stock').textContent  = textoStock();
  actualizarCuotas();

  document.querySelectorAll('#pdp-opciones .pdp__opcion')
    .forEach((b, n) => b.classList.toggle('is-activa', n === i));
}

function cambiarCantidad(delta) {
  const max = stockActual() || 1;
  CANTIDAD = Math.max(1, Math.min(CANTIDAD + delta, max));
  document.getElementById('pdp-qty').textContent = CANTIDAD;
}

function agregarAlCarrito() {
  if (!PRODUCTO) return;
  if (PRODUCTO.variantes.length && !VARIANTE) {
    window.showToast('Elegí una fragancia antes de agregar al carrito.', 'error');
    return;
  }
  if (stockActual() === 0) {
    window.showToast('Esta opción no tiene stock disponible.', 'error');
    return;
  }
  if (typeof window.Carrito === 'undefined') {
    window.showToast('Error: módulo de carrito no disponible.', 'error');
    return;
  }

  window.Carrito.agregar({
    id:              PRODUCTO.id,
    nombre:          PRODUCTO.nombre,
    marca:           PRODUCTO.marca,
    precio:          precioActual(),
    img:             fotoPrincipal(),
    stock:           stockActual(),
    variante_id:     VARIANTE ? VARIANTE.id : null,
    variante_nombre: VARIANTE ? VARIANTE.nombre : null,
  }, CANTIDAD);

  window.showToast(`"${nombreActual()}" agregado al carrito.`, 'success');
}

document.addEventListener('DOMContentLoaded', cargarProducto);
