// ============================================================
// KABODHI Admin — layout.js
// Sidebar compartido. Cada pagina solo declara:
//   <aside class="sidebar" id="sidebar" data-page="productos"></aside>
// ============================================================

const SIDEBAR_SECCIONES = [
  {
    label: 'Principal',
    links: [
      { page: 'dashboard',  href: 'dashboard.html',  icon: '◈', text: 'Dashboard' },
      { page: 'productos',  href: 'productos.html',  icon: '◆', text: 'Productos' },
      { page: 'categorias', href: 'categorias.html', icon: '❖', text: 'Categorías' },
      { page: 'hongos',     href: 'hongos.html',     icon: '✦', text: 'Hongos principales' },
      { page: 'pedidos',    href: 'pedidos.html',    icon: '◇', text: 'Pedidos' },
      { page: 'envios',     href: 'envios.html',     icon: '◎', text: 'Envíos' },
    ],
  },
  {
    label: 'Ajustes',
    // Solo para el administrador principal: un operador trabaja con el
    // catalogo y los pedidos, no con usuarios ni con la configuracion.
    soloSuper: true,
    links: [
      { page: 'configuracion', href: 'configuracion.html', icon: '⚙', text: 'Configuración' },
      { page: 'usuarios',      href: 'usuarios.html',      icon: '☺', text: 'Usuarios' },
    ],
  },
  {
    label: 'Tienda',
    links: [
      { page: null, href: '../index.html', icon: '↗', text: 'Ver tienda', blank: true },
    ],
  },
];

function renderSidebar() {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  const actual = sidebar.dataset.page || '';

  const esSuper = !window.ADMIN_ACTUAL || window.ADMIN_ACTUAL.rol === 'super';

  const nav = SIDEBAR_SECCIONES
    .filter(seccion => !seccion.soloSuper || esSuper)
    .map((seccion, i) => `
    <div class="sidebar__nav-label"${i > 0 ? ' style="margin-top:1.5rem;"' : ''}>${seccion.label}</div>
    ${seccion.links.map(l => `
      <a href="${l.href}"${l.blank ? ' target="_blank"' : ''}
         class="sidebar__link${l.page === actual ? ' active' : ''}">
        <span class="sidebar__link-icon">${l.icon}</span> ${l.text}
      </a>
    `).join('')}
  `).join('');

  sidebar.innerHTML = `
    <div class="sidebar__header">
      <span class="sidebar__logo">KABODHI</span>
      <span class="sidebar__subtitle">Admin Panel</span>
    </div>
    <nav class="sidebar__nav">${nav}</nav>
    <div class="sidebar__footer">
      <div class="sidebar__user">Bienvenido, <span id="admin-username">Admin</span></div>
      <button class="sidebar__logout" id="btn-logout">
        <span>↩</span> Cerrar sesión
      </button>
    </div>
  `;

  // El innerHTML de arriba tira los listeners: hay que volver a engancharlos.
  if (typeof window.enlazarSidebar === 'function') window.enlazarSidebar();
}

// Se registra antes que el listener de auth.js (layout.js se carga primero),
// asi el sidebar ya existe cuando auth.js engancha el logout y el hamburger.
document.addEventListener('DOMContentLoaded', renderSidebar);

// checkAuth() resuelve despues y recien ahi se conoce el rol: se vuelve a
// dibujar para esconder lo que el operador no debe ver.
window.renderSidebar = renderSidebar;
