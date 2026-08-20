// ============================================================
// KABODHI Admin — hongos.js (hongos principales)
// ============================================================

let allHongos = [];
let editingId = null;
let imagenActual = null; // { url, file, preview } | null

// ---- Fetch ----
async function fetchHongos() {
  try {
    const tbody = document.getElementById('hongos-tbody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="6" class="loading">Cargando...</td></tr>`;
    const res  = await fetch(API_URL + '/hongos?all=1', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al cargar hongos.');
    allHongos = (json.data || []).sort((a, b) => (a.orden - b.orden) || (a.id - b.id));
    renderTabla(allHongos);
  } catch (err) {
    showToast(err.message, 'error');
    document.getElementById('hongos-tbody').innerHTML =
      `<tr><td colspan="6" style="text-align:center;color:var(--taupe);padding:2rem;">${err.message}</td></tr>`;
  }
}

// ---- Render ----
function renderTabla(hongos) {
  const tbody = document.getElementById('hongos-tbody');
  if (!tbody) return;

  if (!hongos.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--taupe);padding:2rem;">Sin hongos cargados.</td></tr>`;
    return;
  }

  tbody.innerHTML = hongos.map(h => `
    <tr>
      <td style="font-weight:600;">${h.orden}</td>
      <td>
        <img class="table-img" src="${h.imagen_url || 'https://via.placeholder.com/44x44/F5F1E8/1F3D2E?text=K'}"
             alt="${escHtml(h.nombre)}"
             onerror="this.src='https://via.placeholder.com/44x44/F5F1E8/1F3D2E?text=K'">
      </td>
      <td><strong style="font-weight:500;">${escHtml(h.nombre)}</strong></td>
      <td style="color:var(--taupe);font-size:0.82rem;">${escHtml(h.subtitulo || '')}</td>
      <td>
        <button onclick="toggleActivo(${h.id}, ${h.activo})"
          class="badge badge--${parseInt(h.activo) === 1 ? 'activo' : 'inactivo'}"
          style="border:none;cursor:pointer;font-family:var(--font);"
          title="${parseInt(h.activo) === 1 ? 'Clic para desactivar' : 'Clic para activar'}"
        >${parseInt(h.activo) === 1 ? 'Activo' : 'Inactivo'}</button>
      </td>
      <td>
        <div style="display:flex;gap:0.4rem;">
          <button class="btn btn-secondary btn-sm" onclick="openModal(${h.id})">Editar</button>
          <button class="btn btn-danger btn-sm" onclick="deleteHongo(${h.id}, '${escHtml(h.nombre)}')">✕</button>
        </div>
      </td>
    </tr>`).join('');
}

// ---- Imagen ----
function renderImagen() {
  const cont = document.getElementById('f-imagen-preview');
  if (!cont) return;
  if (!imagenActual) { cont.innerHTML = ''; return; }
  cont.innerHTML = `
    <div style="position:relative;width:120px;height:90px;">
      <img src="${imagenActual.preview || imagenActual.url}" style="width:120px;height:90px;object-fit:cover;border-radius:4px;border:1px solid var(--champagne);">
      <button type="button" onclick="removeImagen()"
        style="position:absolute;top:-6px;right:-6px;background:#c07b7b;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:0.65rem;">✕</button>
    </div>`;
}
function removeImagen() { imagenActual = null; renderImagen(); }
window.removeImagen = removeImagen;

// ---- Modal ----
async function openModal(id = null) {
  editingId = id;
  const modal = document.getElementById('modal-overlay');
  const form  = document.getElementById('hongo-form');
  const title = document.getElementById('modal-title');

  form.reset();
  document.getElementById('f-imagen-file').value = '';
  imagenActual = null;
  renderImagen();

  if (id !== null) {
    title.textContent = 'Editar Hongo';
    try {
      const res  = await fetch(API_URL + '/hongos/' + id, { credentials: 'include' });
      const json = await res.json();
      const h    = json.data;
      if (h) {
        document.getElementById('f-nombre').value      = h.nombre       || '';
        document.getElementById('f-subtitulo').value   = h.subtitulo    || '';
        document.getElementById('f-orden').value       = h.orden        ?? '';
        document.getElementById('f-descripcion').value = h.descripcion  || '';
        document.getElementById('f-activo').checked    = parseInt(h.activo) === 1;
        if (h.imagen_url) { imagenActual = { url: h.imagen_url, file: null, preview: h.imagen_url }; renderImagen(); }
      }
    } catch (e) {
      showToast('Error al cargar el hongo.', 'error');
    }
  } else {
    title.textContent = 'Nuevo Hongo';
    document.getElementById('f-activo').checked = true;
    document.getElementById('f-orden').value    = (allHongos.length + 1);
  }

  modal.classList.add('open');
}
window.openModal = openModal;

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  editingId = null;
  imagenActual = null;
}

// ---- Save ----
async function saveHongo() {
  const nombre = document.getElementById('f-nombre').value.trim();
  if (!nombre) { showToast('El nombre es obligatorio.', 'error'); return; }

  const saveBtn = document.getElementById('btn-save');
  saveBtn.disabled = true;

  // Subir imagen pendiente
  if (imagenActual && imagenActual.file) {
    saveBtn.textContent = 'Subiendo foto...';
    const fd = new FormData();
    fd.append('imagen', imagenActual.file);
    try {
      const res  = await fetch(API_URL + '/upload', { method: 'POST', credentials: 'include', body: fd });
      const json = await res.json();
      if (!json.success) throw new Error(json.message || 'Error al subir la foto.');
      imagenActual = { url: json.url, file: null, preview: json.url };
    } catch (err) {
      showToast(err.message, 'error');
      saveBtn.disabled = false; saveBtn.textContent = 'Guardar';
      return;
    }
  }

  saveBtn.textContent = 'Guardando...';

  const payload = {
    nombre,
    subtitulo:   document.getElementById('f-subtitulo').value.trim(),
    descripcion: document.getElementById('f-descripcion').value.trim(),
    orden:       parseInt(document.getElementById('f-orden').value) || 0,
    activo:      document.getElementById('f-activo').checked ? 1 : 0,
    imagen_url:  imagenActual ? imagenActual.url : null,
  };

  try {
    const url    = editingId !== null ? API_URL + '/hongos/' + editingId : API_URL + '/hongos';
    const method = editingId !== null ? 'PUT' : 'POST';
    const res  = await fetch(url, {
      method, credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al guardar.');
    showToast(editingId ? 'Hongo actualizado.' : 'Hongo creado.', 'success');
    closeModal();
    fetchHongos();
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    saveBtn.disabled = false; saveBtn.textContent = 'Guardar';
  }
}
window.saveHongo = saveHongo;

// ---- Delete ----
async function deleteHongo(id, nombre) {
  if (!confirm(`¿Eliminar "${nombre}"? Se quita del inicio de forma permanente.`)) return;
  try {
    const res  = await fetch(API_URL + '/hongos/' + id, { method: 'DELETE', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al eliminar.');
    showToast('Hongo eliminado.', 'success');
    fetchHongos();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.deleteHongo = deleteHongo;

// ---- Toggle activo ----
async function toggleActivo(id, activo) {
  const nuevo = parseInt(activo) === 1 ? 0 : 1;
  try {
    const res  = await fetch(API_URL + '/hongos/' + id, {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ activo: nuevo }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al actualizar.');
    showToast(`Hongo ${nuevo === 1 ? 'activado' : 'desactivado'}.`, 'success');
    fetchHongos();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.toggleActivo = toggleActivo;

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  const authenticated = await checkAuth();
  if (!authenticated) return;

  fetchHongos();

  document.getElementById('btn-nuevo-hongo')?.addEventListener('click', () => openModal(null));
  document.getElementById('btn-cancel-modal')?.addEventListener('click', closeModal);
  document.getElementById('btn-close-modal')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
  });

  document.getElementById('f-imagen-file')?.addEventListener('change', function () {
    if (this.files.length) {
      const file = this.files[0];
      const reader = new FileReader();
      reader.onload = e => { imagenActual = { url: null, file, preview: e.target.result }; renderImagen(); };
      reader.readAsDataURL(file);
    }
    this.value = '';
  });
});
