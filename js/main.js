// ============================================================
// KABODHI — main.js
// ============================================================

// ---- Iconos ----
// Van como SVG en la pagina: Font Awesome eran 103 KB de CSS mas sus fuentes,
// traidos de otro dominio, para dos iconos.
const ICONO_BOLSA = '<svg class="icono" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';

// ---- Navbar HTML ----
function getNavbarHTML() {
  return `
    <header class="navbar" id="main-navbar">

      <div class="navbar__logo-wrap">
        <a href="${PAGES_BASE}/" class="navbar__logo-link" aria-label="KABODHI — Inicio">
          <img src="images/logo-circulo.webp" alt="" class="navbar__logo-circle" aria-hidden="true">
          <img src="images/logo-nombre.webp" alt="KABODHI" class="navbar__logo-name">
        </a>
      </div>

      <nav class="navbar__nav">
        <a href="${PAGES_BASE}/" class="navbar__link" data-page="index">INICIO</a>
        <a href="${PAGES_BASE}/productos" class="navbar__link" data-page="productos">PRODUCTOS</a>
        <a href="${PAGES_BASE}/nosotros" class="navbar__link" data-page="nosotros">SOBRE KABODHI</a>
        <a href="${PAGES_BASE}/contacto" class="navbar__link" data-page="contacto">CONTACTO</a>
      </nav>

      <div class="navbar__icons">
        <!-- Sin icono de perfil: la tienda no tiene cuentas de cliente y solo
             llevaba al login del panel, que usa la administradora. -->
        <a href="${PAGES_BASE}/carrito" class="navbar__cart" aria-label="Carrito">
          ${ICONO_BOLSA}
          <span class="cart-badge" id="cart-badge" style="display:none;">0</span>
        </a>
        <button class="navbar__hamburger" id="hamburger-btn" aria-label="Menú">
          <span></span><span></span><span></span>
        </button>
      </div>

    </header>

    <div class="mobile-menu" id="mobile-menu">
      <a href="${PAGES_BASE}/">Inicio</a>
      <a href="${PAGES_BASE}/productos">Productos</a>
      <a href="${PAGES_BASE}/nosotros">Sobre KABODHI</a>
      <a href="${PAGES_BASE}/contacto">Contacto</a>
      <a href="${PAGES_BASE}/carrito">Carrito</a>
    </div>
  `;
}

// ---- Footer HTML ----
/**
 * Email y WhatsApp del footer, desde la configuracion de la tienda.
 * Antes estaban escritos a mano y el telefono era un placeholder
 * (+54 11 0000-0000) que se veia en todas las paginas.
 */
async function rellenarContactoFooter() {
  if (typeof configLista !== 'undefined') await configLista;

  const mail = document.getElementById('footer-email');
  if (mail) mail.textContent = CONTACTO_EMAIL || '';

  const dir = document.getElementById('footer-direccion');
  if (dir) dir.textContent = DIRECCION || '';

  const ig = document.getElementById('footer-instagram');
  if (ig) {
    if (INSTAGRAM_USUARIO) {
      ig.href = 'https://instagram.com/' + INSTAGRAM_USUARIO;
      ig.textContent = '@' + INSTAGRAM_USUARIO;
      ig.style.display = '';
    } else {
      ig.style.display = 'none';
    }
  }

  const wa = document.getElementById('footer-whatsapp');
  if (wa && WHATSAPP_NUMERO) {
    const n = WHATSAPP_NUMERO;
    wa.textContent = n.length >= 12
      ? `+${n.slice(0, 2)} ${n.slice(2, 4)} ${n.slice(4, 8)}-${n.slice(8)}`
      : '+' + n;
  }
}

function getFooterHTML() {
  return `
    <footer class="footer">
      <div class="footer__top-wrap">
      <div class="footer__top">
        <div>
          <img src="images/logo-footer-kabodhi.webp?v=2" alt="KABODHI — Adaptógenos naturales para tu bienestar diario" class="footer__logo-img" loading="lazy" decoding="async">
        </div>

        <div>
          <div class="footer__heading">Navegación</div>
          <nav class="footer__nav">
            <a href="${PAGES_BASE}/">Inicio</a>
            <a href="${PAGES_BASE}/productos">Productos</a>
            <a href="${PAGES_BASE}/nosotros">Nosotros</a>
            <a href="${PAGES_BASE}/contacto">Contacto</a>
            <a href="${PAGES_BASE}/ayuda">Ayuda</a>
          </nav>
        </div>

        <div>
          <div class="footer__heading">Contacto</div>
          <p class="footer__contact-item" id="footer-email"></p>
          <p class="footer__contact-item" id="footer-whatsapp"></p>
          <p class="footer__contact-item" id="footer-direccion"></p>
          <div class="footer__heading" style="margin-top:1.5rem;">Redes</div>
          <a href="#" class="footer__contact-item" id="footer-instagram" target="_blank" rel="noopener noreferrer"></a>
        </div>
      </div>
      </div>

      <div class="footer__bottom">
        <p class="footer__copy">
          &copy; ${new Date().getFullYear()} KABODHI . Todos los derechos reservados.
        </p>
        <p class="footer__credito">
          Hecho por <a href="https://www.margonsoftware.com/" target="_blank" rel="noopener noreferrer">MargonSoftware</a>
        </p>
      </div>
    </footer>
  `;
}

// ---- Toast container ----
function createToastContainer() {
  if (document.getElementById('toast-container')) return;
  const div = document.createElement('div');
  div.id = 'toast-container';
  div.className = 'toast-container';
  document.body.appendChild(div);
}

// ---- showToast ----
window.showToast = function(message, type = 'info') {
  createToastContainer();
  const container = document.getElementById('toast-container');

  const icons = { success: '✓', error: '✕', info: '◆' };
  const icon = icons[type] || icons.info;

  const toast = document.createElement('div');
  toast.className = `toast toast--${type}`;
  toast.innerHTML = `
    <span class="toast__icon">${icon}</span>
    <span>${message}</span>
  `;

  container.appendChild(toast);

  // Trigger animation
  requestAnimationFrame(() => {
    requestAnimationFrame(() => toast.classList.add('show'));
  });

  // Auto remove
  setTimeout(() => {
    toast.classList.remove('show');
    toast.addEventListener('transitionend', () => toast.remove(), { once: true });
  }, 3500);
};

// ---- Cart counter badge ----
function updateCartBadge() {
  const badge = document.getElementById('cart-badge');
  if (!badge) return;

  try {
    const raw = localStorage.getItem('kabodhi_cart_v2');
    if (!raw) { badge.style.display = 'none'; return; }
    const carrito = JSON.parse(raw);
    const total = (carrito.items || []).reduce((sum, item) => sum + (item.cantidad || 0), 0);
    if (total > 0) {
      badge.textContent = total > 99 ? '99+' : total;
      badge.style.display = 'flex';
    } else {
      badge.style.display = 'none';
    }
  } catch {
    badge.style.display = 'none';
  }
}

window.updateCartBadge = updateCartBadge;

// ---- Active nav link ----
function setActiveNavLink() {
  const path = window.location.pathname;
  const filename = path.split('/').pop().replace('.html', '') || 'index';

  document.querySelectorAll('.navbar__link[data-page]').forEach(link => {
    const page = link.getAttribute('data-page');
    if (page === filename) {
      link.classList.add('active');
    } else {
      link.classList.remove('active');
    }
  });
}

// ---- Hamburger toggle ----
function setupHamburger() {
  const btn = document.getElementById('hamburger-btn');
  const menu = document.getElementById('mobile-menu');
  if (!btn || !menu) return;

  btn.addEventListener('click', () => {
    menu.classList.toggle('open');
    document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
  });

  // Close on link click
  menu.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      menu.classList.remove('open');
      document.body.style.overflow = '';
    });
  });
}

// ---- Scroll reveal (animaciones de entrada) ----
// Chequeo por posicion (scroll + rect): robusto y sin depender de IntersectionObserver.
function setupReveal() {
  const revealInView = () => {
    const vh = window.innerHeight || document.documentElement.clientHeight;
    document.querySelectorAll('.reveal:not(.is-visible)').forEach(el => {
      const r = el.getBoundingClientRect();
      // Se muestra cuando su borde superior entra ~12% desde abajo de la pantalla.
      // (No exige bottom>0, asi los que ya pasaron nunca quedan ocultos.)
      if (r.top < vh * 0.88) {
        el.classList.add('is-visible');
      }
    });
  };

  // Expuesto para que el contenido dinamico (hongos, mas vendidos) lo dispare tras renderizar
  window.observeReveals = revealInView;

  window.addEventListener('scroll', revealInView, { passive: true });
  window.addEventListener('resize', revealInView, { passive: true });

  // Primera pasada (elementos ya visibles al cargar) + una de respaldo
  revealInView();
  setTimeout(revealInView, 300);
}


// ---- Navbar scroll behaviour ----
function setupNavbarScroll() {
  const navbar = document.getElementById('main-navbar');
  if (!navbar) return;

  window.addEventListener('scroll', () => {
    if (window.scrollY > 20) {
      navbar.classList.add('scrolled');
    } else {
      navbar.classList.remove('scrolled');
    }
  }, { passive: true });
}

// ---- Dropdown click ----
function setupDropdowns() {
  document.querySelectorAll('.navbar__dropdown').forEach(dropdown => {
    const trigger = dropdown.querySelector('.navbar__link');
    if (!trigger) return;

    trigger.addEventListener('click', e => {
      e.preventDefault();
      const isOpen = dropdown.classList.contains('open');
      document.querySelectorAll('.navbar__dropdown').forEach(d => d.classList.remove('open'));
      if (!isOpen) dropdown.classList.add('open');
    });
  });

  document.addEventListener('click', e => {
    if (!e.target.closest('.navbar__dropdown')) {
      document.querySelectorAll('.navbar__dropdown').forEach(d => d.classList.remove('open'));
    }
  });
}

// ---- Smooth scroll for # links ----
function setupSmoothScroll() {
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', (e) => {
      const target = document.querySelector(anchor.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth' });
      }
    });
  });
}

// ---- Format currency ----
window.formatMoney = function(amount) {
  return new Intl.NumberFormat('es-AR', {
    style: 'currency',
    currency: 'ARS',
    minimumFractionDigits: 0,
  }).format(amount);
};

// ============================================================
// DOMContentLoaded init
// ============================================================
document.addEventListener('DOMContentLoaded', () => {

  // Inject navbar
  const navbarEl = document.getElementById('navbar');
  if (navbarEl) {
    navbarEl.innerHTML = getNavbarHTML();
  }

  // Inject footer
  const footerEl = document.getElementById('footer');
  if (footerEl) {
    footerEl.innerHTML = getFooterHTML();
    rellenarContactoFooter();
  }

  updateCartBadge();
  setActiveNavLink();
  setupHamburger();
  setupNavbarScroll();
  setupSmoothScroll();
  setupDropdowns();
  setupReveal();

  // Listen for cart changes from other scripts
  window.addEventListener('carrito-updated', updateCartBadge);
});
