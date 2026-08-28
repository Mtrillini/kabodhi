// ============================================================
// KABODHI Admin — productos.js
// ============================================================

let allProductos   = [];
let editingId      = null;
let imagenesActuales = [];
// Each item: { url: string|null, file: File|null, preview: string }

let categorias = [];

// ---- Categorias ----
async function fetchCategorias() {
  try {
    const res  = await fetch(API_URL + '/categorias', { credentials: 'include' });
    const json = await res.json();
    categorias = json.data || [];
  } catch {
    categorias = [];
  }

  const select = document.getElementById('f-categoria');
  if (!select) return;

  if (!categorias.length) {
    select.innerHTML = '<option value="">Sin categorías</option>';
    return;
  }
  select.innerHTML = categorias
    .map(c => `<option value="${c.id}">${escHtml(c.nombre)}</option>`)
    .join('');
}

// ---- Fetch ----
async function fetchProductos() {
  try {
    showTableLoading();
    const res  = await fetch(API_URL + '/productos?incluir_inactivos=1', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al cargar productos.');
    allProductos = json.data || [];
    // Orden armonioso: por marca A→Z y, dentro de cada marca, por nombre A→Z
    allProductos.sort((a, b) =>
      (a.marca || '').localeCompare(b.marca || '', 'es') ||
      (a.nombre || '').localeCompare(b.nombre || '', 'es')
    );
    renderTabla(allProductos);
  } catch (err) {
    showToast(err.message, 'error');
    document.getElementById('productos-tbody').innerHTML =
      `<tr><td colspan="8" style="text-align:center;color:var(--taupe);padding:2rem;">${err.message}</td></tr>`;
  }
}

function showTableLoading() {
  const tbody = document.getElementById('productos-tbody');
  if (tbody) tbody.innerHTML = `<tr><td colspan="8" class="loading">Cargando...</td></tr>`;
}

// ---- Render table ----
function renderTabla(productos) {
  const tbody = document.getElementById('productos-tbody');
  if (!tbody) return;

  if (!productos.length) {
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--taupe);padding:2rem;">Sin productos.</td></tr>`;
    return;
  }

  tbody.innerHTML = productos.map(p => {
    const firstImg = (p.imagenes && p.imagenes.length) ? p.imagenes[0].url : (p.imagen_url || '');
    return `
    <tr>
      <td>
        <img
          class="table-img"
          src="${mediaUrl(firstImg) || IMG_PLACEHOLDER}"
          alt="${escHtml(p.nombre)}"
          onerror="this.onerror=null;this.src=IMG_PLACEHOLDER"
        >
      </td>
      <td>
        <strong style="font-weight:500;">${escHtml(p.nombre)}</strong>
        ${parseInt(p.destacado) === 1 ? '<span title="Destacado" style="margin-left:6px;font-size:0.75rem;">⭐</span>' : ''}
        <div style="font-size:0.7rem;color:var(--taupe);margin-top:2px;">${escHtml(p.nota_olfativa || '').slice(0,50)}${(p.nota_olfativa||'').length > 50 ? '...' : ''}</div>
      </td>
      <td>${escHtml(p.categoria_nombre || '')}</td>
      <td><span class="badge badge--activo">${capitalize(p.tipo)}</span></td>
      <td>${formatMoney(p.precio)}</td>
      <td>
        <span style="font-weight:600;color:${parseInt(p.stock) === 0 ? '#c07b7b' : parseInt(p.stock) < 5 ? '#d4931a' : 'inherit'};">
          ${p.stock}
        </span>
      </td>
      <td>
        <button
          onclick="toggleActivo(${p.id}, ${p.activo})"
          class="badge badge--${parseInt(p.activo) === 1 ? 'activo' : 'inactivo'}"
          style="border:none;cursor:pointer;font-family:var(--font);"
          title="${parseInt(p.activo) === 1 ? 'Clic para desactivar' : 'Clic para activar'}"
        >${parseInt(p.activo) === 1 ? 'Activo' : 'Inactivo'}</button>
      </td>
      <td>
        <div style="display:flex;gap:0.4rem;">
          <button class="btn btn-secondary btn-sm" onclick="openModal(${p.id})" title="Editar">Editar</button>
          <button class="btn btn-danger btn-sm" onclick="deleteProducto(${p.id})" title="Eliminar (si ya se vendió, queda desactivado)">✕</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

// ---- Image management ----
function renderImagenes() {
  const lista = document.getElementById('f-imagenes-lista');
  if (!lista) return;

  if (!imagenesActuales.length) {
    lista.innerHTML = '';
    return;
  }

  lista.innerHTML = imagenesActuales.map((img, i) => `
    <div style="position:relative;width:80px;height:80px;flex-shrink:0;">
      <img src="${mediaUrl(img.preview || img.url)}" style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid var(--champagne);" alt="imagen ${i+1}">
      <button
        type="button"
        onclick="removeImagen(${i})"
        style="position:absolute;top:-6px;right:-6px;background:#c07b7b;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:0.65rem;display:flex;align-items:center;justify-content:center;line-height:1;"
      >✕</button>
      ${i === 0 ? '<span style="position:absolute;bottom:2px;left:2px;background:rgba(0,0,0,0.55);color:#fff;font-size:0.5rem;padding:1px 4px;border-radius:2px;">Principal</span>' : ''}
    </div>
  `).join('');
}

function removeImagen(index) {
  imagenesActuales.splice(index, 1);
  renderImagenes();
}
window.removeImagen = removeImagen;

function addImagenesFromFiles(files) {
  Array.from(files).forEach(file => {
    const reader = new FileReader();
    reader.onload = (e) => {
      imagenesActuales.push({ url: null, file, preview: e.target.result });
      renderImagenes();
    };
    reader.readAsDataURL(file);
  });
}

// ---- Open modal ----
async function openModal(id = null) {
  editingId = id;
  const modal = document.getElementById('modal-overlay');
  const form  = document.getElementById('producto-form');
  const title = document.getElementById('modal-title');

  form.reset();
  document.getElementById('f-imagen-file').value = '';
  imagenesActuales = [];
  renderImagenes();

  if (id !== null) {
    title.textContent = 'Editar Producto';
    try {
      const res  = await fetch(API_URL + '/productos/' + id, { credentials: 'include' });
      const json = await res.json();
      const p    = json.data;
      if (p) {
        document.getElementById('f-marca').value        = p.marca         || '';
        document.getElementById('f-nombre').value       = p.nombre        || '';
        document.getElementById('f-descripcion').value  = p.descripcion   || '';
        document.getElementById('f-nota').value         = p.nota_olfativa || '';
        document.getElementById('f-precio').value       = p.precio        || '';
        document.getElementById('f-stock').value        = p.stock         ?? '';
        document.getElementById('f-tipo').value         = p.tipo          || 'enfoque';
        document.getElementById('f-categoria').value    = p.categoria_id  || (categorias[0]?.id ?? '');
        document.getElementById('f-activo').checked     = parseInt(p.activo)    === 1;
        document.getElementById('f-destacado').checked  = parseInt(p.destacado) === 1;

        // Load existing images
        if (p.imagenes && p.imagenes.length) {
          imagenesActuales = p.imagenes.map(img => ({ url: img.url, file: null, preview: img.url }));
        } else if (p.imagen_url) {
          imagenesActuales = [{ url: p.imagen_url, file: null, preview: p.imagen_url }];
        }
        renderImagenes();
      }
    } catch (e) {
      showToast('Error al cargar producto.', 'error');
    }
  } else {
    title.textContent = 'Nuevo Producto';
    document.getElementById('f-activo').checked  = true;
    document.getElementById('f-tipo').value      = 'enfoque';
    document.getElementById('f-categoria').value = categorias[0]?.id ?? '';
  }

  modal.classList.add('open');
}
window.openModal = openModal;

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  editingId = null;
  imagenesActuales = [];
}

// ---- Save (create / update) ----
async function saveProducto() {
  const nombre = document.getElementById('f-nombre').value.trim();
  const precio = parseFloat(document.getElementById('f-precio').value);

  if (!nombre) { showToast('El nombre es obligatorio.', 'error'); return; }
  if (isNaN(precio) || precio <= 0) { showToast('Ingresá un precio válido.', 'error'); return; }

  const saveBtn = document.getElementById('btn-save');
  saveBtn.disabled    = true;
  saveBtn.textContent = 'Subiendo imágenes...';

  // Upload pending files
  for (let i = 0; i < imagenesActuales.length; i++) {
    const item = imagenesActuales[i];
    if (item.file) {
      const formData = new FormData();
      formData.append('imagen', item.file);
      try {
        const res  = await fetch(API_URL + '/upload', { method: 'POST', credentials: 'include', body: formData });
        const json = await res.json();
        if (!json.success) throw new Error(json.message || 'Error al subir imagen.');
        imagenesActuales[i] = { url: json.url, file: null, preview: json.url };
      } catch (err) {
        showToast(err.message, 'error');
        saveBtn.disabled    = false;
        saveBtn.textContent = 'Guardar';
        return;
      }
    }
  }

  saveBtn.textContent = 'Guardando...';

  const payload = {
    marca:         document.getElementById('f-marca').value.trim(),
    nombre,
    descripcion:   document.getElementById('f-descripcion').value.trim(),
    nota_olfativa: document.getElementById('f-nota').value.trim(),
    precio,
    stock:         parseInt(document.getElementById('f-stock').value) || 0,
    tipo:          document.getElementById('f-tipo').value,
    categoria_id:  parseInt(document.getElementById('f-categoria').value) || 1,
    activo:        document.getElementById('f-activo').checked    ? 1 : 0,
    destacado:     document.getElementById('f-destacado').checked ? 1 : 0,
    imagenes:      imagenesActuales.map(img => img.url).filter(Boolean),
  };

  try {
    const url    = editingId !== null ? API_URL + '/productos/' + editingId : API_URL + '/productos';
    const method = editingId !== null ? 'PUT' : 'POST';

    const res  = await fetch(url, {
      method,
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await res.json();

    if (!json.success) throw new Error(json.message || 'Error al guardar producto.');

    showToast(editingId ? 'Producto actualizado.' : 'Producto creado.', 'success');
    closeModal();
    fetchProductos();
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    saveBtn.disabled    = false;
    saveBtn.textContent = 'Guardar';
  }
}
window.saveProducto = saveProducto;

// ---- Delete ----
async function deleteProducto(id) {
  // El nombre se busca aca y no se interpola en el atributo onclick: escHtml
  // no escapa comillas, y un nombre con ' permitiria inyectar JS.
  const producto = allProductos.find(x => parseInt(x.id) === parseInt(id));
  const nombre   = producto ? producto.nombre : '#' + id;
  if (!confirm(`¿Eliminar "${nombre}"?

Si nunca se vendió, se borra definitivamente. Si ya figura en algún pedido, se desactiva para no romper ese historial.`)) return;
  try {
    const res  = await fetch(API_URL + '/productos/' + id, { method: 'DELETE', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al eliminar.');

    // Cuando queda desactivado hay que explicar por que sigue en la lista.
    showToast(json.message || 'Producto eliminado.', json.resultado === 'eliminado' ? 'success' : 'info');
    fetchProductos();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.deleteProducto = deleteProducto;

// ---- Toggle activo ----
async function toggleActivo(id, activo) {
  const nuevoEstado = parseInt(activo) === 1 ? 0 : 1;
  const label = nuevoEstado === 1 ? 'activar' : 'desactivar';
  if (!confirm(`¿Querés ${label} este producto?`)) return;

  try {
    const res  = await fetch(API_URL + '/productos/' + id, {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ activo: nuevoEstado }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al actualizar.');
    showToast(`Producto ${nuevoEstado === 1 ? 'activado' : 'desactivado'}.`, 'success');
    fetchProductos();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.toggleActivo = toggleActivo;

// ---- Search filter ----
function filterProductos(query) {
  const q = query.toLowerCase().trim();
  if (!q) { renderTabla(allProductos); return; }
  const filtered = allProductos.filter(p =>
    p.nombre.toLowerCase().includes(q) ||
    (p.categoria_nombre || '').toLowerCase().includes(q) ||
    p.tipo.toLowerCase().includes(q)
  );
  renderTabla(filtered);
}

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1);
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  const authenticated = await checkAuth();
  if (!authenticated) return;

  await fetchCategorias();
  fetchProductos();

  document.getElementById('btn-nuevo-producto')?.addEventListener('click', () => openModal(null));
  document.getElementById('btn-cancel-modal')?.addEventListener('click', closeModal);
  document.getElementById('btn-close-modal')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
  });

  // Multi-image file input
  document.getElementById('f-imagen-file')?.addEventListener('change', function () {
    if (this.files.length) addImagenesFromFiles(this.files);
    this.value = ''; // allow re-selecting same files
  });

  // Search
  document.getElementById('search-productos')?.addEventListener('input', function () {
    filterProductos(this.value);
  });
});
