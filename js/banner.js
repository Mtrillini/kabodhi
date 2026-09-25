// ============================================================
// KABODHI — banner.js
// Banner animado con detalles de promociones/info de la tienda.
// Se mueve de lado a lado (marquee) con texto dinámico, sin cortes.
// ============================================================

async function loadBannerDetalles() {
  // config.js ya pide /configuracion una sola vez para toda la pagina y la
  // deja en CONFIG_TIENDA: banner.js espera esa misma promesa en vez de
  // volver a pedirla (evita una llamada a la API duplicada en cada pagina).
  try {
    if (typeof configLista !== 'undefined') await configLista;
  } catch (e) { /* si config.js fallo, se sigue igual: CONFIG_TIENDA queda {} */ }

  const bd = typeof CONFIG_TIENDA !== 'undefined' ? CONFIG_TIENDA.banner_detalles : null;
  if (typeof bd === 'string' && bd.trim() !== '') {
    try {
      const arr = JSON.parse(bd);
      if (Array.isArray(arr)) return arr.map(d => String(d).trim()).filter(Boolean);
    } catch (e) { }
  }

  return [];
}

/**
 * Arma el ticker sin huecos: mide el ancho real de una vuelta de texto y la
 * repite las veces necesarias para cubrir de sobra el ancho de la pantalla.
 * Después duplica ese bloque ya "lleno" en dos copias IDENTICAS: al animar
 * el contenedor de translateX(0) a translateX(-50%), el punto donde termina
 * la copia 1 y empieza la copia 2 es pixel-perfecto (son el mismo contenido),
 * asi que el reinicio del loop es invisible.
 */
function armarTicker(cont, detalles) {
  const separador = '   —   ';
  const textoBase = detalles.join(separador) + separador;

  // Medir el ancho real de una vuelta con la tipografia ya aplicada al banner.
  const estilo = window.getComputedStyle(cont);
  const medidor = document.createElement('span');
  medidor.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;top:-9999px;';
  medidor.style.font = estilo.font;
  medidor.style.letterSpacing = estilo.letterSpacing;
  medidor.style.textTransform = estilo.textTransform;
  medidor.textContent = textoBase;
  document.body.appendChild(medidor);
  const anchoUnaVuelta = medidor.offsetWidth || 1;
  document.body.removeChild(medidor);

  // Repetir hasta cubrir 2x el ancho de la ventana (de sobra para que nunca
  // se vea el final del contenido antes de que el track vuelva a arrancar).
  const anchoObjetivo = window.innerWidth * 2;
  const repeticiones  = Math.max(1, Math.ceil(anchoObjetivo / anchoUnaVuelta));
  const bloque = textoBase.repeat(repeticiones);

  cont.innerHTML = `
    <div class="banner-scroll" id="banner-scroll-track">
      <span class="banner-content">${bloque}</span>
      <span class="banner-content" aria-hidden="true">${bloque}</span>
    </div>
  `;

  // Velocidad constante (~50px/seg) sin importar cuanto texto haya.
  const track = document.getElementById('banner-scroll-track');
  requestAnimationFrame(() => {
    const anchoBloque = track.scrollWidth / 2;
    const duracion = Math.max(8, anchoBloque / 50);
    track.style.animationDuration = duracion + 's';
  });
}

async function renderBanner() {
  const cont = document.getElementById('banner-detalles');
  if (!cont) return;

  const detalles = await loadBannerDetalles();
  if (!detalles.length) {
    cont.style.display = 'none';
    return;
  }

  cont.style.display = 'block';
  armarTicker(cont, detalles);
}

document.addEventListener('DOMContentLoaded', renderBanner);

// Si la ventana cambia mucho de ancho (rotar el celular, redimensionar),
// se re-arma el ticker para que la cantidad de repeticiones siga siendo la justa.
let bannerResizeTimeout;
window.addEventListener('resize', () => {
  clearTimeout(bannerResizeTimeout);
  bannerResizeTimeout = setTimeout(() => {
    const cont = document.getElementById('banner-detalles');
    if (cont && cont.style.display !== 'none') renderBanner();
  }, 400);
});
