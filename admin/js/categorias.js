// ============================================================
// KABODHI Admin — categorias.js
// ============================================================

let allCategorias = [];
let editingId     = null;

async function fetchCategorias() {
  const tbody = document.getElementById('categorias-tbody');
  tbody.innerHTML = `<tr><td colspan="4" class="loading">Cargando...</td></tr>`;

  try {
    const res  = await fetch(API_URL + '/categorias', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al cargar categorías.');
    allCategorias = json.data || [];
    renderTabla();
  } catch (err) {
    showToast(err.message, 'error');
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--taupe);padding:2rem;">${err.message}</td></tr>`;
  }
}

function renderTabla() {
  const tbody = document.getElementById('categorias-tbody');

  if (!allCategorias.length) {
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--taupe);padding:2rem;">Sin categorías.</td></tr>`;
    return;
  }

  tbody.innerHTML = allCategorias.map(c => {
    const enUso = parseInt(c.total_productos) > 0;
    return `
    <tr>
      <td><strong style="font-weight:500;">${escHtml(c.nombre)}</strong></td>
      <td style="color:var(--taupe);font-size:0.75rem;">${escHtml(c.slug)}</td>
      <td>${c.total_productos}</td>
      <td>
        <div style="display:flex;gap:0.4rem;">
          <button class="btn btn-secondary btn-sm" onclick="openModal(${c.id})">Editar</button>
          <button
            class="btn btn-danger btn-sm"
            onclick="deleteCategoria(${c.id})"
            ${enUso ? 'disabled title="Tiene productos asignados"' : 'title="Eliminar"'}
            style="${enUso ? 'opacity:0.4;cursor:not-allowed;' : ''}"
          >✕</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function openModal(id = null) {
  editingId = id;
  document.getElementById('modal-title').textContent = id === null ? 'Nueva Categoría' : 'Editar Categoría';
  document.getElementById('categoria-form').reset();

  if (id !== null) {
    const c = allCategorias.find(x => parseInt(x.id) === id);
    if (c) {
      document.getElementById('f-nombre').value = c.nombre;
      document.getElementById('f-slug').value   = c.slug;
    }
  }

  document.getElementById('modal-overlay').classList.add('open');
  document.getElementById('f-nombre').focus();
}
window.openModal = openModal;

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  editingId = null;
}

async function saveCategoria() {
  const nombre = document.getElementById('f-nombre').value.trim();
  if (!nombre) { showToast('El nombre es obligatorio.', 'error'); return; }

  const slug = document.getElementById('f-slug').value.trim();
  const btn  = document.getElementById('btn-save');
  btn.disabled    = true;
  btn.textContent = 'Guardando...';

  // Slug vacío: que lo genere el backend a partir del nombre.
  const payload = slug ? { nombre, slug } : { nombre };

  try {
    const res = await fetch(
      editingId !== null ? API_URL + '/categorias/' + editingId : API_URL + '/categorias',
      {
        method: editingId !== null ? 'PUT' : 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }
    );
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al guardar.');

    showToast(editingId !== null ? 'Categoría actualizada.' : 'Categoría creada.', 'success');
    closeModal();
    fetchCategorias();
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Guardar';
  }
}

async function deleteCategoria(id) {
  const cat = allCategorias.find(x => parseInt(x.id) === id);
  const nombre = cat ? cat.nombre : '#' + id;
  if (!confirm(`¿Eliminar la categoría "${nombre}"? Esta acción no se puede deshacer.`)) return;

  try {
    const res  = await fetch(API_URL + '/categorias/' + id, { method: 'DELETE', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al eliminar.');
    showToast('Categoría eliminada.', 'success');
    fetchCategorias();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.deleteCategoria = deleteCategoria;

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

document.addEventListener('DOMContentLoaded', async () => {
  if (!await checkAuth()) return;
  fetchCategorias();

  document.getElementById('btn-nueva-categoria')?.addEventListener('click', () => openModal(null));
  document.getElementById('btn-save')?.addEventListener('click', saveCategoria);
  document.getElementById('btn-cancel-modal')?.addEventListener('click', closeModal);
  document.getElementById('btn-close-modal')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
  });
});
