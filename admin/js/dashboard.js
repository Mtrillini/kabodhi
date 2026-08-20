// ============================================================
// KABODHI Admin — dashboard.js
// ============================================================

async function fetchStats() {
  try {
    const [pedidosRes, productosRes] = await Promise.all([
      fetch(API_URL + '/pedidos', { credentials: 'include' }),
      fetch(API_URL + '/productos', { credentials: 'include' }),
    ]);

    const pedidosJson   = await pedidosRes.json();
    const productosJson = await productosRes.json();

    const pedidos   = pedidosJson.data   || [];
    const productos = productosJson.data || [];

    // Stats
    const today = new Date().toISOString().slice(0, 10);
    const pedidosHoy = pedidos.filter(p => p.created_at && p.created_at.startsWith(today)).length;

    const startOfMonth = new Date();
    startOfMonth.setDate(1);
    startOfMonth.setHours(0, 0, 0, 0);
    const ingresosMes = pedidos
      .filter(p => p.estado === 'aprobado' && new Date(p.created_at) >= startOfMonth)
      .reduce((sum, p) => sum + parseFloat(p.total || 0), 0);

    const stockBajo = productos.filter(p => parseInt(p.stock) < 5 && parseInt(p.activo) === 1).length;

    // Render stat cards
    document.getElementById('stat-total-productos').textContent = productos.length;
    document.getElementById('stat-stock-bajo').textContent = stockBajo;
    document.getElementById('stat-pedidos-hoy').textContent = pedidosHoy;
    document.getElementById('stat-ingresos-mes').textContent = formatMoney(ingresosMes);

    // Recent orders (last 10)
    renderRecentOrders(pedidos.slice(0, 10));

    // Low stock products
    const lowStockProductos = productos
      .filter(p => parseInt(p.stock) < 5 && parseInt(p.activo) === 1)
      .slice(0, 8);
    renderLowStock(lowStockProductos);

  } catch (err) {
    console.error('Error fetching stats:', err);
    showToast('Error al cargar datos del dashboard.', 'error');
  }
}

function renderRecentOrders(pedidos) {
  const tbody = document.getElementById('recent-orders-tbody');
  if (!tbody) return;

  if (!pedidos.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--taupe);padding:2rem;">Sin pedidos recientes.</td></tr>`;
    return;
  }

  tbody.innerHTML = pedidos.map(p => `
    <tr>
      <td>#${p.id}</td>
      <td>${escHtml(p.cliente_nombre)}</td>
      <td style="color:var(--taupe);">${escHtml(p.cliente_email)}</td>
      <td>${formatMoney(p.total)}</td>
      <td><span class="badge badge--${p.estado}">${capitalize(p.estado)}</span></td>
      <td style="color:var(--taupe);">${formatDate(p.created_at)}</td>
    </tr>
  `).join('');
}

function renderLowStock(productos) {
  const tbody = document.getElementById('low-stock-tbody');
  if (!tbody) return;

  if (!productos.length) {
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--taupe);padding:2rem;">Sin productos con stock bajo.</td></tr>`;
    return;
  }

  tbody.innerHTML = productos.map(p => `
    <tr>
      <td>
        <img
          class="table-img"
          src="${p.imagen_url || 'https://via.placeholder.com/44x44/F2ECE6/0D0D0D?text=N'}"
          alt="${escHtml(p.nombre)}"
          onerror="this.src='https://via.placeholder.com/44x44/F2ECE6/0D0D0D?text=N'"
        >
      </td>
      <td>${escHtml(p.nombre)}</td>
      <td>
        <span style="font-weight:600;color:${parseInt(p.stock) === 0 ? '#c07b7b' : '#d4931a'};">
          ${p.stock}
        </span>
      </td>
      <td><span class="badge badge--${p.tipo === 'tester' ? 'cancelado' : 'activo'}">${capitalize(p.tipo)}</span></td>
    </tr>
  `).join('');
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

function formatDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  const authenticated = await checkAuth();
  if (authenticated) {
    fetchStats();
  }
});
