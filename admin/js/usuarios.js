// ============================================================
// KABODHI Admin — usuarios.js
// ============================================================

let allUsuarios = [];
let passwordId  = null; // usuario cuyo password se esta cambiando

async function fetchUsuarios() {
  const tbody = document.getElementById('usuarios-tbody');
  tbody.innerHTML = `<tr><td colspan="4" class="loading">Cargando...</td></tr>`;

  try {
    const res  = await fetch(API_URL + '/usuarios', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al cargar usuarios.');
    allUsuarios = json.data || [];
    renderTabla();
  } catch (err) {
    showToast(err.message, 'error');
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--taupe);padding:2rem;">${err.message}</td></tr>`;
  }
}

function esPropio(id) {
  return window.ADMIN_ACTUAL && parseInt(window.ADMIN_ACTUAL.id) === parseInt(id);
}

function renderTabla() {
  const tbody = document.getElementById('usuarios-tbody');

  if (!allUsuarios.length) {
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--taupe);padding:2rem;">Sin usuarios.</td></tr>`;
    return;
  }

  const unico = allUsuarios.length === 1;

  tbody.innerHTML = allUsuarios.map(u => {
    const propio      = esPropio(u.id);
    const noSePuede   = propio || unico;
    const motivoTitle = propio ? 'No podés eliminar tu propio usuario'
                       : unico ? 'Es el único administrador'
                       : 'Eliminar';
    return `
    <tr>
      <td>
        <strong style="font-weight:500;">${escHtml(u.username)}</strong>
        ${propio ? '<span class="badge badge--activo" style="margin-left:0.5rem;">vos</span>' : ''}
      </td>
      <td style="color:var(--taupe);">${escHtml(u.email)}</td>
      <td style="color:var(--taupe);">${formatDate(u.created_at)}</td>
      <td>
        <div style="display:flex;gap:0.4rem;">
          <button class="btn btn-secondary btn-sm" onclick="openPassword(${u.id})">Contraseña</button>
          <button
            class="btn btn-danger btn-sm"
            onclick="deleteUsuario(${u.id})"
            ${noSePuede ? 'disabled' : ''}
            title="${motivoTitle}"
            style="${noSePuede ? 'opacity:0.4;cursor:not-allowed;' : ''}"
          >✕</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

// ---- Modales ----
function abrir(id)  { document.getElementById(id).classList.add('open'); }
function cerrar(id) { document.getElementById(id).classList.remove('open'); }

function openNuevo() {
  document.getElementById('nuevo-form').reset();
  abrir('modal-nuevo');
  document.getElementById('f-username').focus();
}

function openPassword(id) {
  passwordId = id;
  const usuario = allUsuarios.find(u => parseInt(u.id) === id);
  const propio  = esPropio(id);

  document.getElementById('password-title').textContent =
    propio ? 'Cambiar mi contraseña' : `Cambiar contraseña de ${usuario ? usuario.username : '#' + id}`;

  // La contraseña actual solo se exige (y se pide) para el propio usuario.
  document.getElementById('grupo-password-actual').style.display = propio ? '' : 'none';
  document.getElementById('password-form').reset();

  abrir('modal-password');
  document.getElementById(propio ? 'f-password-actual' : 'f-password-nueva').focus();
}
window.openPassword = openPassword;

// ---- Acciones ----
async function crearUsuario() {
  const username = document.getElementById('f-username').value.trim();
  const email    = document.getElementById('f-email').value.trim();
  const password = document.getElementById('f-password').value;

  if (!username || !email || !password) {
    showToast('Completá todos los campos.', 'error');
    return;
  }

  const btn = document.getElementById('btn-save-nuevo');
  btn.disabled    = true;
  btn.textContent = 'Creando...';

  try {
    const res  = await fetch(API_URL + '/usuarios', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, email, password }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al crear el usuario.');

    showToast('Usuario creado.', 'success');
    cerrar('modal-nuevo');
    fetchUsuarios();
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Crear usuario';
  }
}

async function guardarPassword() {
  const nueva  = document.getElementById('f-password-nueva').value;
  const actual = document.getElementById('f-password-actual').value;

  if (!nueva) { showToast('Ingresá la contraseña nueva.', 'error'); return; }
  if (esPropio(passwordId) && !actual) {
    showToast('Ingresá tu contraseña actual.', 'error');
    return;
  }

  const btn = document.getElementById('btn-save-password');
  btn.disabled    = true;
  btn.textContent = 'Guardando...';

  try {
    const res  = await fetch(API_URL + '/usuarios/' + passwordId + '/password', {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: nueva, password_actual: actual }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al cambiar la contraseña.');

    showToast('Contraseña actualizada.', 'success');
    cerrar('modal-password');
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Guardar';
  }
}

async function deleteUsuario(id) {
  const usuario = allUsuarios.find(u => parseInt(u.id) === id);
  const nombre  = usuario ? usuario.username : '#' + id;
  if (!confirm(`¿Eliminar al usuario "${nombre}"? Perderá el acceso al panel.`)) return;

  try {
    const res  = await fetch(API_URL + '/usuarios/' + id, { method: 'DELETE', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al eliminar.');
    showToast('Usuario eliminado.', 'success');
    fetchUsuarios();
  } catch (err) {
    showToast(err.message, 'error');
  }
}
window.deleteUsuario = deleteUsuario;

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

document.addEventListener('DOMContentLoaded', async () => {
  if (!await checkAuth()) return;
  fetchUsuarios();

  document.getElementById('btn-nuevo-usuario')?.addEventListener('click', openNuevo);
  document.getElementById('btn-save-nuevo')?.addEventListener('click', crearUsuario);
  document.getElementById('btn-save-password')?.addEventListener('click', guardarPassword);

  document.querySelectorAll('[data-cerrar]').forEach(btn => {
    btn.addEventListener('click', () => cerrar(btn.dataset.cerrar));
  });

  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
      if (e.target === e.currentTarget) cerrar(overlay.id);
    });
  });
});
