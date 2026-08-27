// ============================================================
// KABODHI Admin — envios.js
// ============================================================

let allTarifas = [];
let editingId  = null;

async function fetchTarifas() {
  try {
    document.getElementById('envios-tbody').innerHTML =
      `<tr><td colspan="6" class="loading">Cargando...</td></tr>`;
    const res  = await fetch(API_URL + '/envios', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    allTarifas = json.data || [];
    renderTabla(allTarifas);
  } catch (err) {
    showToast(err.message, 'error');
  }
}

function renderTabla(tarifas) {
  const tbody = document.getElementById('envios-tbody');
  if (!tbody) return;

  if (!tarifas.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--taupe);padding:2rem;">Sin tarifas cargadas.</td></tr>`;
    return;
  }

  const fmt = window.formatMoney || (v => '$ ' + parseFloat(v).toLocaleString('es-AR'));

  tbody.innerHTML = tarifas.map(t => `
    <tr>
      <td><strong style="font-weight:500;">${escHtml(t.descripcion)}</strong></td>
      <td>${t.cp_desde}</td>
      <td>${t.cp_hasta}</td>
      <td>${fmt(t.precio)}</td>
      <td>
        <button
          onclick="toggleActivo(${t.id}, ${t.activo})"
          class="badge badge--${parseInt(t.activo) === 1 ? 'activo' : 'inactivo'}"
          style="border:none;cursor:pointer;font-family:var(--font);"
        >${parseInt(t.activo) === 1 ? 'Activa' : 'Inactiva'}</button>
      </td>
      <td>
        <div style="display:flex;gap:0.4rem;">
          <button class="btn btn-secondary btn-sm" onclick="openModal(${t.id})">Editar</button>
          <button class="btn btn-danger btn-sm" onclick="deleteTarifa(${t.id})">✕</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function openModal(id = null) {
  editingId = id;
  const modal = document.getElementById('modal-overlay');
  document.getElementById('envio-form').reset();
  document.getElementById('f-activo').checked = true;

  if (id !== null) {
    document.getElementById('modal-title').textContent = 'Editar Tarifa';
    const t = allTarifas.find(x => x.id === id);
    if (t) {
      document.getElementById('f-descripcion').value = t.descripcion || '';
      document.getElementById('f-cp-desde').value    = t.cp_desde   || '';
      document.getElementById('f-cp-hasta').value    = t.cp_hasta   || '';
      document.getElementById('f-precio').value      = t.precio     || '';
      document.getElementById('f-activo').checked    = parseInt(t.activo) === 1;
    }
  } else {
    document.getElementById('modal-title').textContent = 'Nueva Tarifa';
  }

  modal.classList.add('open');
}
window.openModal = openModal;

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  editingId = null;
}

async function saveTarifa() {
  const descripcion = document.getElementById('f-descripcion').value.trim();
  const cp_desde    = parseInt(document.getElementById('f-cp-desde').value);
  const cp_hasta    = parseInt(document.getElementById('f-cp-hasta').value);
  const precio      = parseFloat(document.getElementById('f-precio').value);

  if (!descripcion)               { showToast('La descripción es obligatoria.', 'error'); return; }
  if (isNaN(cp_desde) || cp_desde < 1000) { showToast('CP Desde inválido.', 'error'); return; }
  if (isNaN(cp_hasta) || cp_hasta < cp_desde) { showToast('CP Hasta debe ser mayor o igual al CP Desde.', 'error'); return; }
  if (isNaN(precio) || precio < 0) { showToast('Ingresá un precio válido.', 'error'); return; }

  const saveBtn = document.getElementById('btn-save');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Guardando...';

  const payload = {
    descripcion,
    cp_desde,
    cp_hasta,
    precio,
    activo: document.getElementById('f-activo').checked ? 1 : 0,
  };

  try {
    const url    = editingId !== null ? API_URL + '/envios/' + editingId : API_URL + '/envios';
    const method = editingId !== null ? 'PUT' : 'POST';
    const res    = await fetch(url, {
      method,
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al guardar.');
    showToast(editingId ? 'Tarifa actualizada.' : 'Tarifa creada.', 'success');
    closeModal();
    fetchTarifas();
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    saveBtn.disabled = false;
    saveBtn.textContent = 'Guardar';
  }
}
window.saveTarifa = saveTarifa;

async function deleteTarifa(id) {
  // La descripcion se resuelve aca, no se interpola en el onclick (ver productos.js).
  const tarifa = allTarifas.find(x => parseInt(x.id) === parseInt(id));
  const desc   = tarifa ? tarifa.descripcion : '#' + id;
  if (!confirm(`¿Eliminar la tarifa "${desc}"?`)) return;
  try {
    const res  = await fetch(API_URL + '/envios/' + id, { method: 'DELETE', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    showToast('Tarifa eliminada.', 'success');
    fetchTarifas();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.deleteTarifa = deleteTarifa;

async function toggleActivo(id, activo) {
  const nuevo = parseInt(activo) === 1 ? 0 : 1;
  try {
    const res  = await fetch(API_URL + '/envios/' + id, {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ activo: nuevo }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message);
    showToast(nuevo === 1 ? 'Tarifa activada.' : 'Tarifa desactivada.', 'success');
    fetchTarifas();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.toggleActivo = toggleActivo;

function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

document.addEventListener('DOMContentLoaded', async () => {
  const authenticated = await checkAuth();
  if (!authenticated) return;

  fetchTarifas();

  document.getElementById('btn-nueva-tarifa')?.addEventListener('click', () => openModal(null));
  document.getElementById('btn-cancel-modal')?.addEventListener('click', closeModal);
  document.getElementById('btn-close-modal')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
  });
});
