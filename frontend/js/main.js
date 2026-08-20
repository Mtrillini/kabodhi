// ============================================================
// KABODHI — main.js
// ============================================================

// ---- Inject Font Awesome ----
function injectFontAwesome() {
  if (document.querySelector('link[href*="font-awesome"]')) return;
  const fa = document.createElement('link');
  fa.rel = 'stylesheet';
  fa.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
  document.head.appendChild(fa);
}

// ---- Navbar HTML ----
function getNavbarHTML() {
  return `
    <header class="navbar" id="main-navbar">

      <div class="navbar__logo-wrap">
        <a href="${PAGES_BASE}/" class="navbar__logo-link" aria-label="KABODHI — Inicio">
          <img src="${APP_BASE}/frontend/images/logo-circulo.png" alt="" class="navbar__logo-circle" aria-hidden="true">
          <img src="${APP_BASE}/frontend/images/logo-nombre.png" alt="KABODHI" class="navbar__logo-name">
        </a>
      </div>

      <nav class="navbar__nav">
        <a href="${PAGES_BASE}/" class="navbar__link" data-page="index">INICIO</a>
        <div class="navbar__dropdown">
          <a href="${PAGES_BASE}/productos" class="navbar__link" data-page="productos">PRODUCTOS</a>
          <div class="navbar__dropdown-menu">
            <a href="${PAGES_BASE}/productos" class="navbar__dropdown-item">TODOS</a>
            <a href="${PAGES_BASE}/productos?categoria=adaptogenos" class="navbar__dropdown-item">ADAPTÓGENOS</a>
            <a href="${PAGES_BASE}/productos?categoria=blends" class="navbar__dropdown-item">BLENDS</a>
          </div>
        </div>
        <a href="${PAGES_BASE}/nosotros" class="navbar__link" data-page="nosotros">SOBRE KABODHI</a>
        <a href="${PAGES_BASE}/contacto" class="navbar__link" data-page="contacto">CONTACTO</a>
      </nav>

      <div class="navbar__icons">
        <button class="navbar__icon-btn navbar__search-toggle" id="search-toggle" aria-label="Buscar">
          <i class="fa-solid fa-magnifying-glass"></i>
        </button>
        <a href="${PAGES_BASE}/admin/login.html" class="navbar__icon-btn" aria-label="Ingresar">
          <i class="fa-regular fa-user"></i>
        </a>
        <a href="${PAGES_BASE}/carrito" class="navbar__cart" aria-label="Carrito">
          <i class="fa-solid fa-bag-shopping"></i>
          <span class="cart-badge" id="cart-badge" style="display:none;">0</span>
        </a>
        <button class="navbar__hamburger" id="hamburger-btn" aria-label="Menú">
          <span></span><span></span><span></span>
        </button>
      </div>

    </header>

    <form class="navbar__search" id="navbar-search" role="search" action="${PAGES_BASE}/productos" method="get">
      <input type="search" name="search" placeholder="Buscar hongo o beneficio..." aria-label="Buscar productos">
    </form>

    <div class="mobile-menu" id="mobile-menu">
      <a href="${PAGES_BASE}/">Inicio</a>
      <a href="${PAGES_BASE}/productos">Productos</a>
      <a href="${PAGES_BASE}/productos?categoria=adaptogenos">Adaptógenos</a>
      <a href="${PAGES_BASE}/productos?categoria=blends">Blends</a>
      <a href="${PAGES_BASE}/nosotros">Sobre KABODHI</a>
      <a href="${PAGES_BASE}/contacto">Contacto</a>
      <a href="${PAGES_BASE}/carrito">Carrito</a>
    </div>
  `;
}

// ---- Footer HTML ----
function getFooterHTML() {
  return `
    <section class="esencia">
      <div class="esencia__title">La esencia de KABODHI</div>
      <div class="esencia__divider"></div>
      <div class="esencia__grid">
        <div class="esencia__item">
          <div class="esencia__icon"><i class="fa-solid fa-leaf"></i></div>
          <div class="esencia__label">100% Natural</div>
          <div class="esencia__desc">Ingredientes puros y adaptógenos reales.</div>
        </div>
        <div class="esencia__item">
          <div class="esencia__icon"><i class="fa-solid fa-flask"></i></div>
          <div class="esencia__label">Doble extracción</div>
          <div class="esencia__desc">Máxima biodisponibilidad de cada hongo.</div>
        </div>
        <div class="esencia__item">
          <div class="esencia__icon"><i class="fa-solid fa-spa"></i></div>
          <div class="esencia__label">Equilibrio</div>
          <div class="esencia__desc">Apoyo real a tu cuerpo y tu mente.</div>
        </div>
        <div class="esencia__item">
          <div class="esencia__icon"><i class="fa-solid fa-seedling"></i></div>
          <div class="esencia__label">Consciente</div>
          <div class="esencia__desc">Bienestar de elecciones sostenibles.</div>
        </div>
      </div>
    </section>
 <section class="historia">
  <div class="historia__inner">
    <span class="historia__overline">Nuestra esencia</span>

    <h2 class="historia__title">¿Por qué KABODHI?</h2>

    <p class="historia__text">
      KABODHI representa el equilibrio natural entre cuerpo, mente y entorno.
      Inspirados en la sabiduría ancestral y en rituales conscientes, elaboramos
      adaptógenos naturales y puros que se integran a tu vida con simpleza y
      propósito. Volver a lo natural. Encontrar el equilibrio. Vivir en bienestar.
    </p>

    <a href="nosotros.html" class="historia__link">Conocer nuestra historia</a>
  </div>
</section>
    <footer class="footer">
      <div class="footer__top">
        <div>
          <span class="footer__logo-text">KABODHI</span>
          <p class="footer__tagline">Adaptógenos naturales<br>para tu bienestar diario.</p>
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
          <p class="footer__contact-item">hola@kabodhi.com</p>
          <p class="footer__contact-item">+54 11 0000-0000</p>
          <p class="footer__contact-item">Buenos Aires, Argentina</p>
          <div class="footer__heading" style="margin-top:1.5rem;">Redes</div>
          <a href="https://instagram.com/kabodhi" class="footer__contact-item" target="_blank" rel="noopener noreferrer">@kabodhi</a>
        </div>
      </div>

      <div class="footer__bottom">
        <p class="footer__copy">
          &copy; ${new Date().getFullYear()} KABODHI . Todos los derechos reservados.
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
    const raw = localStorage.getItem('nuve_cart');
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

// ---- Search toggle ----
function setupSearch() {
  const toggle = document.getElementById('search-toggle');
  const box    = document.getElementById('navbar-search');
  if (!toggle || !box) return;

  const input = box.querySelector('input');

  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    box.classList.toggle('open');
    if (box.classList.contains('open') && input) input.focus();
  });

  document.addEventListener('click', (e) => {
    if (!box.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
      box.classList.remove('open');
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') box.classList.remove('open');
  });
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
  injectFontAwesome();

  // Inject navbar
  const navbarEl = document.getElementById('navbar');
  if (navbarEl) {
    navbarEl.innerHTML = getNavbarHTML();
  }

  // Inject footer
  const footerEl = document.getElementById('footer');
  if (footerEl) {
    footerEl.innerHTML = getFooterHTML();
  }

  updateCartBadge();
  setActiveNavLink();
  setupHamburger();
  setupSearch();
  setupNavbarScroll();
  setupSmoothScroll();
  setupDropdowns();
  setupReveal();

  // Listen for cart changes from other scripts
  window.addEventListener('carrito-updated', updateCartBadge);
});
