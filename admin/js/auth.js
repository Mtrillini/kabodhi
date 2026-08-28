// ============================================================
// KABODHI Admin — auth.js
// ============================================================

/**
 * Para las pantallas que son solo del administrador principal. El backend ya
 * las rechaza; esto evita que un operador vea una pantalla rota si entra por
 * la URL directa.
 */
async function requireSuper() {
  if (!await checkAuth()) return false;

  if (window.ADMIN_ACTUAL && window.ADMIN_ACTUAL.rol !== 'super') {
    document.querySelector('.main')?.insertAdjacentHTML('afterbegin', `
      <div style="margin:2rem;padding:1.5rem;background:#fff;border-radius:4px;
                  font-family:var(--font);font-size:0.85rem;color:var(--taupe);
                  box-shadow:var(--shadow);">
        Esta sección es solo para el administrador principal.
        <a href="dashboard.html" style="color:var(--negro);">Volver al panel</a>.
      </div>`);
    document.querySelector('.content')?.remove();
    return false;
  }
  return true;
}

/**
 * Respaldo: si el panel corre sin PHP y ademas demo.js no cargo, no hay forma
 * de responder la API. Se explica en vez de encadenar errores de conexion.
 */
function avisarSinBackend() {
  document.body.innerHTML = `
    <div style="max-width:520px;margin:14vh auto;padding:2.5rem;font-family:var(--font);
                background:#fff;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,0.08);text-align:center;">
      <div style="font-family:var(--font-display);font-size:1.5rem;letter-spacing:6px;color:#1F3D2E;margin-bottom:0.5rem;">KABODHI</div>
      <h1 style="font-size:1.1rem;font-weight:normal;color:#1F3D2E;margin:1.5rem 0 1rem;">
        El panel necesita un hosting con PHP
      </h1>
      <p style="font-size:0.85rem;color:#8B7966;line-height:1.7;">
        Esta copia está publicada en un hosting estático, donde la API no corre.
        La tienda funciona igual, pero la administración requiere PHP y MySQL.
      </p>
      <a href="../index.html"
         style="display:inline-block;margin-top:1.5rem;padding:0.7rem 1.5rem;background:#1F3D2E;
                color:#F5F1E8;text-decoration:none;border-radius:4px;font-size:0.8rem;letter-spacing:1px;">
        Ir a la tienda
      </a>
    </div>`;
}

async function checkAuth() {
  try {
    const res = await fetch(API_URL + '/auth/check', {
      credentials: 'include',
    });
    const json = await res.json();

    if (!json.success || !json.authenticated) {
      if (typeof PANEL_SIN_BACKEND !== 'undefined' && PANEL_SIN_BACKEND) {
        avisarSinBackend();
        return false;
      }
      window.location.href = APP_BASE + '/admin/login.html';
      return false;
    }

    // Admin de la sesion actual, para las pantallas que lo necesitan
    // (ej: usuarios.js, que no deja borrarse a uno mismo).
    window.ADMIN_ACTUAL = json.admin || null;

    // El sidebar se dibujo antes de saber el rol: se rehace para ocultar
    // Ajustes cuando el usuario es operador.
    if (typeof window.renderSidebar === 'function') window.renderSidebar();

    if (json.modo_dev) mostrarAvisoModoDev();

    // Update username display if present
    const usernameEl = document.getElementById('admin-username');
    if (usernameEl && json.admin) {
      usernameEl.textContent = json.admin.username || json.admin.email || 'Admin';
    }

    return true;
  } catch {
    if (typeof PANEL_SIN_BACKEND !== 'undefined' && PANEL_SIN_BACKEND) {
      avisarSinBackend();
      return false;
    }
    window.location.href = APP_BASE + '/admin/login.html';
    return false;
  }
}

/** Barra fija: el panel esta abierto sin login (solo desarrollo). */
function mostrarAvisoModoDev() {
  if (document.getElementById('aviso-modo-dev')) return;

  const barra = document.createElement('div');
  barra.id = 'aviso-modo-dev';
  barra.textContent = 'MODO DESARROLLO — el panel está abierto sin login. Desactivá DEV_ADMIN_SIN_LOGIN en el .env antes de publicar.';
  barra.style.cssText = [
    'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:9999',
    'background:#A66B3D', 'color:#fff', 'text-align:center',
    'font-family:var(--font)', 'font-size:0.7rem', 'letter-spacing:0.06em',
    'padding:0.4rem 1rem',
  ].join(';');

  document.body.appendChild(barra);
  // Empujar el contenido para que la barra no tape la topbar.
  document.body.style.paddingTop = '1.75rem';
}

async function login(username, password) {
  try {
    const res = await fetch(API_URL + '/auth/login', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });

    const json = await res.json();

    if (json.success) {
      window.location.href = APP_BASE + '/admin/dashboard.html';
    } else {
      return { success: false, message: json.message || 'Error de autenticación.' };
    }
  } catch {
    return { success: false, message: 'Error de conexión. Verificá que la API esté activa.' };
  }
}

async function logout() {
  try {
    await fetch(API_URL + '/auth/logout', {
      method: 'POST',
      credentials: 'include',
    });
  } catch { /* ignore */ }
  window.location.href = APP_BASE + '/admin/login.html';
}

// ---- Toast for admin ----
window.showToast = function(message, type = 'info') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    document.body.appendChild(container);
  }

  const icons = { success: '✓', error: '✕', info: '◆' };
  const toast = document.createElement('div');
  toast.className = `toast toast--${type}`;
  toast.innerHTML = `<span>${icons[type] || '◆'}</span><span>${message}</span>`;

  container.appendChild(toast);
  requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('show')));

  setTimeout(() => {
    toast.classList.remove('show');
    toast.addEventListener('transitionend', () => toast.remove(), { once: true });
  }, 3500);
};

// ---- Format money ----
window.formatMoney = function(amount) {
  return new Intl.NumberFormat('es-AR', {
    style: 'currency',
    currency: 'ARS',
    minimumFractionDigits: 0,
  }).format(amount);
};

// ---- Sidebar hamburger (admin) ----
document.addEventListener('DOMContentLoaded', () => {
  const hamburger = document.getElementById('hamburger-admin');
  const sidebar   = document.getElementById('sidebar');

  if (hamburger && sidebar) {
    hamburger.addEventListener('click', () => {
      sidebar.classList.toggle('open');
    });

    // Close on link click (mobile)
    sidebar.querySelectorAll('.sidebar__link').forEach(link => {
      link.addEventListener('click', () => sidebar.classList.remove('open'));
    });
  }

  // Logout button
  const logoutBtn = document.getElementById('btn-logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', (e) => {
      e.preventDefault();
      logout();
    });
  }
});
