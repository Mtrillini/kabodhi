const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
const isGitHubPages = window.location.hostname.endsWith('github.io');

// Base del sitio segun el entorno:
//  - Local (XAMPP):             /hongos
//  - GitHub Pages:              /<repo> (ej: /kabodhi) — se detecta de la URL
//  - Netlify / dominio propio:  '' (raiz)
let APP_BASE, PAGES_BASE;
if (isLocal) {
  APP_BASE = '/hongos';
  PAGES_BASE = '/hongos';
} else if (isGitHubPages) {
  const seg = window.location.pathname.split('/').filter(Boolean)[0] || '';
  const base = seg ? '/' + seg : '';
  APP_BASE = base;
  PAGES_BASE = base;
} else {
  APP_BASE = '';
  PAGES_BASE = '';
}
const API_URL = APP_BASE + '/api/index.php';

// En hosting estatico (Netlify/GitHub Pages) no corre PHP: se usan datos horneados
// y las acciones (checkout/contacto) van por WhatsApp / mail.
const IS_STATIC  = !isLocal;

// Base de los JSON horneados (rutas relativas para que anden en cualquier host)
const DATA_BASE = 'data/';

// Trae datos: intenta la API PHP y, si no existe (estatico), cae al JSON horneado.
async function fetchDatos(apiPath, staticFile) {
  // En estatico vamos directo al JSON (evita un 404 a la API inexistente).
  if (!IS_STATIC) {
    try {
      const res = await fetch(API_URL + apiPath);
      if (!res.ok) throw new Error('API ' + res.status);
      return await res.json();
    } catch (e) {
      /* cae al JSON horneado */
    }
  }
  const res = await fetch(DATA_BASE + staticFile);
  if (!res.ok) throw new Error('JSON ' + res.status);
  return await res.json();
}

// Configuracion de la tienda. Se edita desde el panel admin (tabla `configuracion`)
// y se carga al inicio; estos son solo los valores de arranque por si la API
// todavia no respondio.
let WHATSAPP_NUMERO   = '';
let CONTACTO_EMAIL    = '';
let ENVIO_GRATIS_DESDE = 0;

// Promesa unica: cualquier pagina puede hacer `await configLista` antes de usar
// WHATSAPP_NUMERO o CONTACTO_EMAIL.
const configLista = (async () => {
  try {
    const json = await fetchDatos('/configuracion', 'configuracion.json');
    const cfg  = json.data || json || {};
    WHATSAPP_NUMERO    = cfg.whatsapp_numero    || WHATSAPP_NUMERO;
    CONTACTO_EMAIL     = cfg.contacto_email     || CONTACTO_EMAIL;
    ENVIO_GRATIS_DESDE = parseFloat(cfg.envio_gratis_desde || 0);
  } catch {
    /* se mantienen los valores de arranque */
  }
  return { WHATSAPP_NUMERO, CONTACTO_EMAIL, ENVIO_GRATIS_DESDE };
})();

const loadProductosData = () => fetchDatos('/productos', 'productos.json');
const loadHongosData    = () => fetchDatos('/hongos',    'hongos.json');
