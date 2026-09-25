// ============================================================
// KABODHI — banner.js
// Banner animado con detalles de promociones/info de la tienda.
// Se mueve de lado a lado (marquee) con texto dinámico.
// ============================================================

async function loadBannerDetalles() {
  try {
    // Primero intenta cargar desde la API: busca banner_detalles en la config general.
    const res = await fetch(API_URL + '/configuracion');
    if (res.ok) {
      const json = await res.json();
      if (json.success && json.data && json.data.banner_detalles) {
        const bd = json.data.banner_detalles;
        if (typeof bd === 'string' && bd.trim() !== '') {
          try {
            const arr = JSON.parse(bd);
            if (Array.isArray(arr)) {
              return arr.filter(d => d && String(d).trim() !== '');
            }
          } catch (e) { }
        }
      }
    }
  } catch (e) {
    // Silenciosamente falla y cae al fallback.
  }

  // Fallback: detalles por defecto (desde CONFIG_TIENDA si existen).
  if (typeof CONFIG_TIENDA !== 'undefined' && CONFIG_TIENDA.banner_detalles) {
    try {
      const arr = JSON.parse(CONFIG_TIENDA.banner_detalles);
      if (Array.isArray(arr)) {
        return arr.filter(d => d && String(d).trim() !== '');
      }
    } catch (e) { }
  }

  return [];
}

async function renderBanner() {
  const cont = document.getElementById('banner-detalles');
  if (!cont) return;

  const detalles = await loadBannerDetalles();
  if (!detalles.length) {
    cont.style.display = 'none';
    return;
  }

  const texto = detalles.map(d => d.trim()).filter(Boolean).join(' — ');

  cont.innerHTML = `
    <div class="banner-scroll">
      <div class="banner-content">
        ${texto}
      </div>
      <div class="banner-content" aria-hidden="true">
        ${texto}
      </div>
    </div>
  `;

  cont.style.display = 'block';
}

document.addEventListener('DOMContentLoaded', renderBanner);
