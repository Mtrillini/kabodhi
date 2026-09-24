// ============================================================
// KABODHI Admin — promos.js
// Combos: el admin arma una lista fija de productos con un precio unico.
// ============================================================

// ---- Helpers (no compartidos entre paginas del panel) ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}
function escAttr(str) {
  return escHtml(str).replace(/"/g, '&quot;');
}

let allPromos    = [];
let allProductos = [];   // catalogo completo, para el checklist de elegibles
let editingId    = null;
let imagenPromo  = null; // { file, preview } o { url }

// ---- Carga ----
async function fetchPromos() {
  const tbody = document.getElementById('promos-tbody');
  tbody.innerHTML = `<tr><td colspan="6" class="loading">Cargando...</td></tr>`;
  try {
    const [resPromos, resProductos] = await Promise.all([
      fetch(API_URL + '/promos?all=1', { credentials: 'include' }),
      fetch(API_URL + '/productos?incluir_inactivos=1', { credentials: 'include' }),
    ]);
    const jsonPromos = await resPromos.json();
    if (!jsonPromos.success) throw new Error(jsonPromos.message || 'Error al cargar los combos.');
    allPromos = jsonPromos.data || [];

    const jsonProductos = await resProductos.json();
    allProductos = jsonProductos.data || [];

    renderTabla();
  } catch (err) {
    showToast(err.message, 'error');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--taupe);padding:2rem;">${err.message}</td></tr>`;
  }
}

function nombreProducto(id) {
  const p = allProductos.find(x => parseInt(x.id) === parseInt(id));
  return p ? p.nombre : `Producto #${id}`;
}

function renderTabla() {
  const tbody = document.getElementById('promos-tbody');
  if (!allPromos.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--taupe);padding:2rem;">
      Todavía no hay combos cargados.</td></tr>`;
    return;
  }

  const fmt = window.formatMoney || (v => '$ ' + parseFloat(v).toLocaleString('es-AR'));

  tbody.innerHTML = allPromos.map(p => {
    const productos = (p.producto_ids || []).map(nombreProducto);
    const imagen = p.imagen_url || (allProductos.find(x => (p.producto_ids || []).includes(parseInt(x.id)))?.imagen_url) || '';
    return `
    <tr>
      <td>
        <img src="${mediaUrl(imagen) || IMG_PLACEHOLDER}" alt=""
             onerror="this.onerror=null;this.src=IMG_PLACEHOLDER"
             style="width:56px;height:56px;object-fit:cover;border-radius:4px;border:1px solid var(--champagne);">
      </td>
      <td><strong style="font-weight:500;">${escHtml(p.nombre)}</strong></td>
      <td>${fmt(p.precio)}</td>
      <td style="font-size:0.75rem;color:var(--taupe);max-width:240px;" title="${escAttr(productos.join(', '))}">
        ${escHtml(productos.join(', ')) || '<span style="color:#c07b7b;">Sin productos</span>'}
      </td>
      <td>
        <button
          onclick="toggleActivo(${p.id}, ${p.activo})"
          class="badge badge--${parseInt(p.activo) === 1 ? 'activo' : 'inactivo'}"
          style="border:none;cursor:pointer;font-family:var(--font);"
        >${parseInt(p.activo) === 1 ? 'Visible' : 'Oculto'}</button>
      </td>
      <td>
        <div style="display:flex;gap:0.4rem;">
          <button class="btn btn-secondary btn-sm" onclick="openModal(${p.id})">Editar</button>
          <button class="btn btn-danger btn-sm" onclick="deletePromo(${p.id})">✕</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

async function toggleActivo(id, activo) {
  const nuevo = parseInt(activo) === 1 ? 0 : 1;
  try {
    const res = await fetch(API_URL + '/promos/' + id, {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ activo: nuevo }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    showToast(nuevo ? 'Combo visible en la tienda.' : 'Combo oculto.', 'success');
    fetchPromos();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.toggleActivo = toggleActivo;

// ---- Imagen ----
function renderPreviewImagen() {
  const cont = document.getElementById('preview-imagen');
  if (!imagenPromo) { cont.innerHTML = ''; return; }
  cont.innerHTML = `<img src="${imagenPromo.preview || mediaUrl(imagenPromo.url)}"
    style="max-width:100%;height:110px;object-fit:cover;border-radius:4px;border:1px solid var(--champagne);">`;
}

function elegirArchivoImagen(file) {
  const lector = new FileReader();
  lector.onload = (e) => {
    imagenPromo = { file, preview: e.target.result };
    renderPreviewImagen();
  };
  lector.readAsDataURL(file);
}

async function subirImagenSiHaceFalta() {
  if (!imagenPromo) return '';
  if (imagenPromo.url) return imagenPromo.url;
  const formData = new FormData();
  formData.append('imagen', imagenPromo.file);
  const res  = await fetch(API_URL + '/upload', { method: 'POST', credentials: 'include', body: formData });
  const json = await res.json();
  if (!json.success) throw new Error(json.message || 'No se pudo subir la imagen.');
  return json.url;
}

// ---- Checklist de productos del combo ----
function renderChecklistProductos(seleccionados) {
  const cont = document.getElementById('f-productos-lista');
  const set  = new Set((seleccionados || []).map(id => parseInt(id)));

  if (!allProductos.length) {
    cont.innerHTML = '<p style="font-size:0.75rem;color:var(--taupe);">No hay productos cargados todavía.</p>';
    return;
  }

  cont.innerHTML = allProductos.map(p => `
    <label style="display:flex;align-items:center;gap:0.55rem;font-size:0.8rem;padding:0.3rem 0;cursor:pointer;">
      <input type="checkbox" class="f-producto-check" value="${p.id}"
             style="width:15px;height:15px;accent-color:var(--negro);"
             ${set.has(parseInt(p.id)) ? 'checked' : ''}>
      <img src="${mediaUrl((p.imagenes && p.imagenes[0] && p.imagenes[0].url) || p.imagen_url) || IMG_PLACEHOLDER}"
           onerror="this.onerror=null;this.src=IMG_PLACEHOLDER"
           style="width:28px;height:28px;object-fit:cover;border-radius:3px;">
      <span>${escHtml(p.nombre)}${parseInt(p.activo) !== 1 ? ' (inactivo)' : ''}</span>
    </label>
  `).join('');
}

function productosSeleccionados() {
  return [...document.querySelectorAll('.f-producto-check:checked')].map(c => parseInt(c.value));
}

// ---- Modal ----
function openModal(id = null) {
  editingId   = id;
  imagenPromo = null;

  document.getElementById('promo-form').reset();
  document.getElementById('modal-title').textContent = id === null ? 'Nuevo combo' : 'Editar combo';

  if (id !== null) {
    const p = allPromos.find(x => parseInt(x.id) === id);
    if (p) {
      document.getElementById('f-nombre').value      = p.nombre || '';
      document.getElementById('f-descripcion').value = p.descripcion || '';
      document.getElementById('f-precio').value       = p.precio || '';
      document.getElementById('f-activo').checked     = parseInt(p.activo) === 1;
      if (p.imagen_url) imagenPromo = { url: p.imagen_url };
      renderChecklistProductos(p.producto_ids);
    }
  } else {
    document.getElementById('f-activo').checked = true;
    renderChecklistProductos([]);
  }

  renderPreviewImagen();
  document.getElementById('modal-overlay').classList.add('open');
}
window.openModal = openModal;

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  editingId   = null;
  imagenPromo = null;
}

async function savePromo() {
  const nombre    = document.getElementById('f-nombre').value.trim();
  const precio    = parseFloat(document.getElementById('f-precio').value);
  const productoIds = productosSeleccionados();

  if (!nombre) { showToast('Ponele un nombre al combo.', 'error'); return; }
  if (isNaN(precio) || precio <= 0) { showToast('Ingresá un precio válido.', 'error'); return; }
  if (productoIds.length < 2) {
    showToast('Tildá al menos 2 productos para armar el combo.', 'error');
    return;
  }

  const saveBtn = document.getElementById('btn-save');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Guardando...';

  try {
    const imagen_url = await subirImagenSiHaceFalta();

    const payload = {
      nombre,
      descripcion:    document.getElementById('f-descripcion').value.trim(),
      precio,
      imagen_url,
      producto_ids:   productoIds,
      activo:         document.getElementById('f-activo').checked ? 1 : 0,
    };

    const url    = editingId !== null ? API_URL + '/promos/' + editingId : API_URL + '/promos';
    const method = editingId !== null ? 'PUT' : 'POST';
    const res    = await fetch(url, {
      method, credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al guardar el combo.');

    showToast(editingId ? 'Combo actualizado.' : 'Combo creado.', 'success');
    closeModal();
    fetchPromos();
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    saveBtn.disabled = false;
    saveBtn.textContent = 'Guardar';
  }
}
window.savePromo = savePromo;

async function deletePromo(id) {
  const p = allPromos.find(x => parseInt(x.id) === id);
  if (!confirm(`¿Eliminar el combo "${p ? p.nombre : '#' + id}"? Los pedidos que ya lo usaron no se ven afectados.`)) return;

  try {
    const res  = await fetch(API_URL + '/promos/' + id, { method: 'DELETE', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    showToast('Combo eliminado.', 'success');
    fetchPromos();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.deletePromo = deletePromo;

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  if (!await checkAuth()) return;
  fetchPromos();

  document.getElementById('btn-nuevo-promo')?.addEventListener('click', () => openModal(null));
  document.getElementById('btn-cancel-modal')?.addEventListener('click', closeModal);
  document.getElementById('btn-close-modal')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
  });
  document.getElementById('f-file-imagen')?.addEventListener('change', function () {
    if (this.files.length) elegirArchivoImagen(this.files[0]);
    this.value = '';
  });
});
