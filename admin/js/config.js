const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
const APP_BASE   = isLocal ? '/hongos' : '';
const PAGES_BASE = isLocal ? '/hongos' : '';
const API_URL    = APP_BASE + '/api/index.php';

// Base para archivos de media (las imagenes se guardan como "images/foo.png",
// relativas a la raiz del sitio, no a /admin/).
const MEDIA_BASE = APP_BASE + '/';

// Resuelve una imagen guardada en la DB a una URL usable desde /admin/.
function mediaUrl(url) {
  if (!url) return '';
  if (/^(https?:|data:|blob:|\/)/i.test(url)) return url;
  return MEDIA_BASE + url.replace(/^\.?\//, '');
}

// Placeholder local (sin depender de servicios externos).
const IMG_PLACEHOLDER =
  'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44">' +
    '<rect width="44" height="44" fill="#e8eef5"/>' +
    '<text x="22" y="28" font-family="Georgia,serif" font-size="16" fill="#2c4a6e" text-anchor="middle">K</text>' +
    '</svg>'
  );
