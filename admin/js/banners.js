// ============================================================
// KABODHI Admin — banners.js
// ============================================================

let allBanners = [];
let editingId  = null;

// Imagen pendiente de subir por slot: { file, preview } o { url }
let imagenes = { desktop: null, mobile: null };

// ---- Carga ----
async function fetchBanners() {
  const tbody = document.getElementById('banners-tbody');
  tbody.innerHTML = `<tr><td colspan="6" class="loading">Cargando...</td></tr>`;

  try {
    const res  = await fetch(API_URL + '/banners?all=1', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al cargar los banners.');
    allBanners = json.data || [];
    renderTabla();
  } catch (err) {
    showToast(err.message, 'error');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--taupe);padding:2rem;">${err.message}</td></tr>`;
  }
}

function renderTabla() {
  const tbody = document.getElementById('banners-tbody');

  if (!allBanners.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--taupe);padding:2rem;">
      Todavía no hay banners. El inicio se ve sin carrusel.</td></tr>`;
    return;
  }

  tbody.innerHTML = allBanners.map((b, i) => `
    <tr>
      <td>
        <div style="display:flex;flex-direction:column;gap:2px;">
          <button class="btn btn-secondary btn-sm" onclick="mover(${b.id}, -1)"
                  ${i === 0 ? 'disabled style="opacity:0.3;"' : ''} title="Subir">▲</button>
          <button class="btn btn-secondary btn-sm" onclick="mover(${b.id}, 1)"
                  ${i === allBanners.length - 1 ? 'disabled style="opacity:0.3;"' : ''} title="Bajar">▼</button>
        </div>
      </td>
      <td>
        <img src="${mediaUrl(b.imagen_desktop)}" alt="${escAttr(b.titulo)}"
             onerror="this.onerror=null;this.src=IMG_PLACEHOLDER"
             style="width:150px;height:56px;object-fit:cover;border-radius:3px;border:1px solid var(--champagne);">
      </td>
      <td>
        <div style="font-size:0.8rem;">${escHtml(b.titulo)}</div>
        ${b.link ? `<div style="font-size:0.7rem;color:var(--taupe);margin-top:2px;">→ ${escHtml(b.link)}</div>` : ''}
      </td>
      <td>
        ${b.imagen_mobile
          ? `<img src="${mediaUrl(b.imagen_mobile)}" alt=""
                  onerror="this.onerror=null;this.src=IMG_PLACEHOLDER"
                  style="width:40px;height:52px;object-fit:cover;border-radius:3px;border:1px solid var(--champagne);">`
          : `<span style="font-size:0.7rem;color:var(--taupe);">usa la de escritorio</span>`}
      </td>
      <td>
        <button onclick="toggleActivo(${b.id}, ${b.activo})"
                class="badge badge--${parseInt(b.activo) === 1 ? 'activo' : 'inactivo'}"
                style="border:none;cursor:pointer;font-family:var(--font);"
                title="${parseInt(b.activo) === 1 ? 'Clic para ocultarlo' : 'Clic para mostrarlo'}">
          ${parseInt(b.activo) === 1 ? 'Visible' : 'Oculto'}
        </button>
      </td>
      <td>
        <div style="display:flex;gap:0.4rem;">
          <button class="btn btn-secondary btn-sm" onclick="openModal(${b.id})">Editar</button>
          <button class="btn btn-danger btn-sm" onclick="deleteBanner(${b.id})" title="Eliminar">✕</button>
        </div>
      </td>
    </tr>
  `).join('');
}

// ---- Orden ----
async function mover(id, delta) {
  const i = allBanners.findIndex(b => parseInt(b.id) === id);
  const j = i + delta;
  if (i < 0 || j < 0 || j >= allBanners.length) return;

  // Se reordena en memoria y se manda la lista completa: mas simple que
  // calcular intercambios en el servidor.
  const copia = [...allBanners];
  [copia[i], copia[j]] = [copia[j], copia[i]];

  try {
    const res  = await fetch(API_URL + '/banners/reordenar', {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ orden: copia.map(b => b.id) }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    allBanners = copia;
    renderTabla();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.mover = mover;

async function toggleActivo(id, activo) {
  const nuevo = parseInt(activo) === 1 ? 0 : 1;
  try {
    const res  = await fetch(API_URL + '/banners/' + id, {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ activo: nuevo }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    showToast(nuevo ? 'Banner visible en el inicio.' : 'Banner oculto.', 'success');
    fetchBanners();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.toggleActivo = toggleActivo;

// ---- Modal ----
function renderPreview(slot) {
  const cont = document.getElementById('preview-' + slot);
  const img  = imagenes[slot];
  if (!cont) return;

  if (!img) { cont.innerHTML = ''; return; }

  const alto = slot === 'mobile' ? 130 : 90;
  cont.innerHTML = `<img src="${img.preview || mediaUrl(img.url)}"
    style="max-width:100%;height:${alto}px;object-fit:cover;border-radius:4px;border:1px solid var(--champagne);">`;
}

function elegirArchivo(slot, file) {
  const lector = new FileReader();
  lector.onload = (e) => {
    imagenes[slot] = { file, preview: e.target.result };
    renderPreview(slot);
  };
  lector.readAsDataURL(file);
}

async function openModal(id = null) {
  editingId = id;
  imagenes  = { desktop: null, mobile: null };

  document.getElementById('banner-form').reset();
  document.getElementById('modal-title').textContent = id === null ? 'Nuevo banner' : 'Editar banner';

  if (id !== null) {
    const b = allBanners.find(x => parseInt(x.id) === id);
    if (b) {
      document.getElementById('f-titulo').value = b.titulo || '';
      document.getElementById('f-link').value   = b.link   || '';
      document.getElementById('f-activo').checked = parseInt(b.activo) === 1;
      if (b.imagen_desktop) imagenes.desktop = { url: b.imagen_desktop };
      if (b.imagen_mobile)  imagenes.mobile  = { url: b.imagen_mobile };
    }
  } else {
    document.getElementById('f-activo').checked = true;
  }

  renderPreview('desktop');
  renderPreview('mobile');
  document.getElementById('modal-overlay').classList.add('open');
}
window.openModal = openModal;

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  editingId = null;
  imagenes  = { desktop: null, mobile: null };
}

/** Sube lo que haya pendiente y devuelve la URL guardada. */
async function subirSiHaceFalta(slot) {
  const img = imagenes[slot];
  if (!img) return '';
  if (img.url) return img.url;

  const formData = new FormData();
  formData.append('imagen', img.file);

  const res  = await fetch(API_URL + '/upload', { method: 'POST', credentials: 'include', body: formData });
  const json = await res.json();
  if (!json.success) throw new Error(json.message || 'No se pudo subir la imagen.');
  return json.url;
}

async function saveBanner() {
  const titulo = document.getElementById('f-titulo').value.trim();
  if (!titulo) { showToast('Poné una descripción del banner.', 'error'); return; }
  if (!imagenes.desktop) { showToast('Falta la imagen de escritorio.', 'error'); return; }

  const btn = document.getElementById('btn-save');
  btn.disabled = true;
  btn.textContent = 'Subiendo...';

  try {
    const payload = {
      titulo,
      imagen_desktop: await subirSiHaceFalta('desktop'),
      imagen_mobile:  await subirSiHaceFalta('mobile'),
      link:           document.getElementById('f-link').value.trim(),
      activo:         document.getElementById('f-activo').checked ? 1 : 0,
    };

    btn.textContent = 'Guardando...';

    const res = await fetch(
      editingId !== null ? API_URL + '/banners/' + editingId : API_URL + '/banners',
      {
        method: editingId !== null ? 'PUT' : 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }
    );
    const json = await res.json();
    if (!json.success) throw new Error(json.message);

    showToast(editingId !== null ? 'Banner actualizado.' : 'Banner creado.', 'success');
    closeModal();
    fetchBanners();
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Guardar';
  }
}

async function deleteBanner(id) {
  const b = allBanners.find(x => parseInt(x.id) === id);
  if (!confirm(`¿Eliminar el banner "${b ? b.titulo : '#' + id}"? Deja de mostrarse en el inicio.`)) return;

  try {
    const res  = await fetch(API_URL + '/banners/' + id, { method: 'DELETE', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    showToast('Banner eliminado.', 'success');
    fetchBanners();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.deleteBanner = deleteBanner;

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function escAttr(str) {
  return escHtml(str).replace(/"/g, '&quot;');
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  if (!await checkAuth()) return;
  fetchBanners();

  document.getElementById('btn-nuevo-banner')?.addEventListener('click', () => openModal(null));
  document.getElementById('btn-save')?.addEventListener('click', saveBanner);
  document.getElementById('btn-cancel-modal')?.addEventListener('click', closeModal);
  document.getElementById('btn-close-modal')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
  });

  ['desktop', 'mobile'].forEach(slot => {
    document.getElementById('f-file-' + slot)?.addEventListener('change', function () {
      if (this.files.length) elegirArchivo(slot, this.files[0]);
      this.value = '';
    });
  });
});
