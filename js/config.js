const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
const APP_BASE   = isLocal ? '/hongos' : '';
const PAGES_BASE = isLocal ? '/hongos' : '';
const API_URL    = APP_BASE + '/api/index.php';

// En hosting estatico (Netlify/GitHub Pages) no corre PHP: se usan datos horneados
// y las acciones (checkout/contacto) van por WhatsApp / mail.
const IS_STATIC  = !isLocal;

// WhatsApp para pedidos/consultas en modo estatico (reemplazar por el numero real)
const WHATSAPP_NUMERO = '5491100000000';
const CONTACTO_EMAIL  = 'hola@kabodhi.com';

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

const loadProductosData = () => fetchDatos('/productos', 'productos.json');
const loadHongosData    = () => fetchDatos('/hongos',    'hongos.json');
