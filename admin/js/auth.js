// ============================================================
// KABODHI Admin — auth.js
// ============================================================

async function checkAuth() {
  try {
    const res = await fetch(API_URL + '/auth/check', {
      credentials: 'include',
    });
    const json = await res.json();

    if (!json.success || !json.authenticated) {
      window.location.href = APP_BASE + '/admin/login.html';
      return false;
    }

    // Update username display if present
    const usernameEl = document.getElementById('admin-username');
    if (usernameEl && json.admin) {
      usernameEl.textContent = json.admin.username || json.admin.email || 'Admin';
    }

    return true;
  } catch {
    window.location.href = APP_BASE + '/admin/login.html';
    return false;
  }
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
