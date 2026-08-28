// ============================================================
// KABODHI Admin — demo.js
//
// GitHub Pages sirve archivos estaticos: no corre PHP, asi que no hay API.
// Este modulo intercepta las llamadas a API_URL y las responde desde el
// navegador, con los datos horneados de data/*.json como punto de partida.
//
// Todo lo que se cargue vive en localStorage de ESTE navegador: no viaja a
// ningun servidor y no lo ve nadie mas. Sirve para probar la interfaz y ver
// como se refleja en la tienda, no para operar de verdad.
// ============================================================

const DEMO_KEY = 'kabodhi_demo_v1';

// ---- Estado ----

function demoLeer() {
  try {
    const raw = localStorage.getItem(DEMO_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function demoGuardar(estado) {
  try {
    localStorage.setItem(DEMO_KEY, JSON.stringify(estado));
    return true;
  } catch (e) {
    // Cuota llena: casi siempre por imagenes subidas en base64.
    showToast('No entra más en el navegador. Borrá alguna imagen cargada o reiniciá la demo.', 'error');
    return false;
  }
}

/** Trae los JSON horneados y arma el estado inicial. */
async function demoSembrar() {
  const traer = async (archivo) => {
    try {
      const res = await fetch('../data/' + archivo, { cache: 'no-store' });
      if (!res.ok) throw new Error();
      const json = await res.json();
      return json.data ?? json;
    } catch {
      return null;
    }
  };

  const productos = (await traer('productos.json')) || [];
  const hongos    = (await traer('hongos.json'))    || [];
  const config    = (await traer('configuracion.json')) || {};

  // Las categorias no estan horneadas: se deducen de los productos.
  const categorias = [];
  productos.forEach(p => {
    const id = parseInt(p.categoria_id);
    if (!id || categorias.some(c => c.id === id)) return;
    categorias.push({
      id,
      nombre: p.categoria_nombre || ('Categoría ' + id),
      slug:   p.categoria_slug   || ('categoria-' + id),
    });
  });

  const estado = {
    productos: productos.map(p => ({ ...p, activo: parseInt(p.activo ?? 1), destacado: parseInt(p.destacado ?? 0) })),
    hongos,
    categorias,
    configuracion: {
      whatsapp_numero:    config.whatsapp_numero    || '',
      contacto_email:     config.contacto_email     || '',
      envio_gratis_desde: config.envio_gratis_desde || '0',
    },
    envios: [
      { id: 1, descripcion: 'Envio CABA',     cp_desde: 1000, cp_hasta: 1499, precio: '3500.00', activo: 1 },
      { id: 2, descripcion: 'Envio GBA',      cp_desde: 1500, cp_hasta: 1900, precio: '4500.00', activo: 1 },
      { id: 3, descripcion: 'Envio Interior', cp_desde: 1901, cp_hasta: 9999, precio: '6500.00', activo: 1 },
    ],
    pedidos: [],
    usuarios: [
      { id: 1, username: 'demo', email: 'demo@kabodhi.com', created_at: new Date().toISOString().slice(0, 19).replace('T', ' ') },
    ],
  };

  demoGuardar(estado);
  return estado;
}

async function demoEstado() {
  return demoLeer() || await demoSembrar();
}

function demoProximoId(lista) {
  return lista.reduce((max, x) => Math.max(max, parseInt(x.id) || 0), 0) + 1;
}

// ---- Respuestas ----

function ok(data, extra = {}) {
  return { success: true, data, ...extra };
}

function jsonResponse(cuerpo, status = 200) {
  return new Response(JSON.stringify(cuerpo), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function error(mensaje, status = 400) {
  return jsonResponse({ success: false, message: mensaje }, status);
}

// ---- Router de la API simulada ----

async function demoResponder(ruta, metodo, cuerpo, params) {
  const estado = await demoEstado();
  const seg    = ruta.split('/').filter(Boolean);
  const s0     = seg[0] || '';
  const s1     = seg[1] || '';
  const s2     = seg[2] || '';
  const id     = parseInt(s1);

  const persistir = () => demoGuardar(estado);

  // --- auth ---
  if (s0 === 'auth') {
    if (s1 === 'check') {
      return jsonResponse({
        success: true, authenticated: true, modo_demo: true,
        admin: { id: 1, username: 'demo', email: 'demo@kabodhi.com' },
      });
    }
    if (s1 === 'login')  return jsonResponse({ success: true, message: 'Modo demo.' });
    if (s1 === 'logout') return jsonResponse({ success: true });
  }

  // --- configuracion ---
  if (s0 === 'configuracion') {
    if (metodo === 'GET') return jsonResponse(ok(estado.configuracion));
    if (metodo === 'PUT') {
      if (cuerpo.contacto_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cuerpo.contacto_email)) {
        return error('El email de contacto no es válido.');
      }
      if (cuerpo.whatsapp_numero !== undefined) {
        cuerpo.whatsapp_numero = String(cuerpo.whatsapp_numero).replace(/\D+/g, '');
      }
      Object.keys(estado.configuracion).forEach(k => {
        if (cuerpo[k] !== undefined) estado.configuracion[k] = String(cuerpo[k]);
      });
      persistir();
      return jsonResponse(ok(estado.configuracion, { message: 'Configuración guardada.' }));
    }
  }

  // --- categorias ---
  if (s0 === 'categorias') {
    if (metodo === 'GET') {
      return jsonResponse(ok(estado.categorias.map(c => ({
        ...c,
        total_productos: estado.productos.filter(p => parseInt(p.categoria_id) === parseInt(c.id)).length,
      }))));
    }
    if (metodo === 'POST') {
      const nombre = (cuerpo.nombre || '').trim();
      if (!nombre) return error('El nombre es obligatorio.');
      const slug = demoSlug(cuerpo.slug || nombre);
      if (estado.categorias.some(c => c.slug === slug)) return error(`Ya existe una categoría con el slug "${slug}".`, 409);
      const nueva = { id: demoProximoId(estado.categorias), nombre, slug };
      estado.categorias.push(nueva);
      persistir();
      return jsonResponse(ok(nueva, { message: 'Categoría creada.' }), 201);
    }
    if (metodo === 'PUT') {
      const cat = estado.categorias.find(c => c.id === id);
      if (!cat) return error('Categoría no encontrada.', 404);
      const nombre = (cuerpo.nombre || '').trim();
      if (!nombre) return error('El nombre es obligatorio.');
      const slug = demoSlug(cuerpo.slug || nombre);
      if (estado.categorias.some(c => c.slug === slug && c.id !== id)) return error(`Ya existe otra categoría con el slug "${slug}".`, 409);
      cat.nombre = nombre; cat.slug = slug;
      persistir();
      return jsonResponse(ok(cat, { message: 'Categoría actualizada.' }));
    }
    if (metodo === 'DELETE') {
      const enUso = estado.productos.filter(p => parseInt(p.categoria_id) === id).length;
      if (enUso > 0) return error(`No se puede eliminar: hay ${enUso} producto(s) en esta categoría.`, 409);
      estado.categorias = estado.categorias.filter(c => c.id !== id);
      persistir();
      return jsonResponse({ success: true, message: 'Categoría eliminada.' });
    }
  }

  // --- productos ---
  if (s0 === 'productos') {
    const conCategoria = (p) => {
      const cat = estado.categorias.find(c => parseInt(c.id) === parseInt(p.categoria_id));
      return { ...p, categoria_nombre: cat ? cat.nombre : '', categoria_slug: cat ? cat.slug : '' };
    };

    if (!s1 && metodo === 'GET') {
      const incluirInactivos = params.get('incluir_inactivos') === '1';
      const lista = estado.productos.filter(p => incluirInactivos || parseInt(p.activo) === 1);
      return jsonResponse(ok(lista.map(conCategoria)));
    }
    if (!s1 && metodo === 'POST') {
      if (!(cuerpo.nombre || '').trim()) return error("El campo 'nombre' es obligatorio.");
      if (!(parseFloat(cuerpo.precio) > 0)) return error("El campo 'precio' es obligatorio.");
      const nuevo = {
        id: demoProximoId(estado.productos),
        categoria_id: parseInt(cuerpo.categoria_id) || (estado.categorias[0]?.id ?? 1),
        marca: cuerpo.marca || '', nombre: cuerpo.nombre.trim(),
        descripcion: cuerpo.descripcion || '', nota_olfativa: cuerpo.nota_olfativa || '',
        precio: String(parseFloat(cuerpo.precio).toFixed(2)),
        stock: parseInt(cuerpo.stock) || 0, stock_reservado: 0,
        imagen_url: (cuerpo.imagenes && cuerpo.imagenes[0]) || '',
        tipo: cuerpo.tipo || 'enfoque',
        activo: cuerpo.activo !== undefined ? parseInt(cuerpo.activo) : 1,
        destacado: parseInt(cuerpo.destacado) || 0,
        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
        imagenes: (cuerpo.imagenes || []).map((url, i) => ({ id: i + 1, url })),
      };
      estado.productos.push(nuevo);
      if (!persistir()) { estado.productos.pop(); return error('No entra más en el navegador.', 507); }
      return jsonResponse(ok(conCategoria(nuevo), { message: 'Producto creado.' }), 201);
    }
    if (id && metodo === 'GET') {
      const p = estado.productos.find(x => parseInt(x.id) === id);
      return p ? jsonResponse(ok(conCategoria(p))) : error('Producto no encontrado.', 404);
    }
    if (id && metodo === 'PUT') {
      const p = estado.productos.find(x => parseInt(x.id) === id);
      if (!p) return error('Producto no encontrado.', 404);
      ['marca','nombre','descripcion','nota_olfativa','tipo'].forEach(k => { if (cuerpo[k] !== undefined) p[k] = cuerpo[k]; });
      if (cuerpo.precio !== undefined)       p.precio = String(parseFloat(cuerpo.precio).toFixed(2));
      if (cuerpo.stock !== undefined)        p.stock = parseInt(cuerpo.stock) || 0;
      if (cuerpo.categoria_id !== undefined) p.categoria_id = parseInt(cuerpo.categoria_id);
      if (cuerpo.activo !== undefined)       p.activo = parseInt(cuerpo.activo);
      if (cuerpo.destacado !== undefined)    p.destacado = parseInt(cuerpo.destacado);
      if (cuerpo.imagenes !== undefined) {
        p.imagenes  = cuerpo.imagenes.map((url, i) => ({ id: i + 1, url }));
        p.imagen_url = cuerpo.imagenes[0] || '';
      }
      persistir();
      return jsonResponse(ok(conCategoria(p), { message: 'Producto actualizado.' }));
    }
    if (id && metodo === 'DELETE') {
      const p = estado.productos.find(x => parseInt(x.id) === id);
      if (!p) return error('Producto no encontrado.', 404);
      p.activo = 0;
      persistir();
      return jsonResponse({ success: true, message: 'Producto eliminado.' });
    }
  }

  // --- hongos ---
  if (s0 === 'hongos') {
    if (!s1 && metodo === 'GET') {
      const todos = params.get('all') === '1';
      return jsonResponse(ok(estado.hongos.filter(h => todos || parseInt(h.activo) === 1)));
    }
    if (!s1 && metodo === 'POST') {
      if (!(cuerpo.nombre || '').trim()) return error('El nombre es obligatorio.');
      const nuevo = {
        id: demoProximoId(estado.hongos),
        nombre: cuerpo.nombre.trim(), subtitulo: cuerpo.subtitulo || '',
        descripcion: cuerpo.descripcion || '', imagen_url: cuerpo.imagen_url || '',
        orden: parseInt(cuerpo.orden) || estado.hongos.length + 1,
        activo: cuerpo.activo !== undefined ? parseInt(cuerpo.activo) : 1,
        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      };
      estado.hongos.push(nuevo);
      if (!persistir()) { estado.hongos.pop(); return error('No entra más en el navegador.', 507); }
      return jsonResponse(ok(nuevo, { message: 'Hongo creado.' }), 201);
    }
    if (id && metodo === 'GET') {
      const h = estado.hongos.find(x => parseInt(x.id) === id);
      return h ? jsonResponse(ok(h)) : error('Hongo no encontrado.', 404);
    }
    if (id && metodo === 'PUT') {
      const h = estado.hongos.find(x => parseInt(x.id) === id);
      if (!h) return error('Hongo no encontrado.', 404);
      ['nombre','subtitulo','descripcion','imagen_url'].forEach(k => { if (cuerpo[k] !== undefined) h[k] = cuerpo[k]; });
      if (cuerpo.orden  !== undefined) h.orden  = parseInt(cuerpo.orden);
      if (cuerpo.activo !== undefined) h.activo = parseInt(cuerpo.activo);
      persistir();
      return jsonResponse(ok(h, { message: 'Hongo actualizado.' }));
    }
    if (id && metodo === 'DELETE') {
      estado.hongos = estado.hongos.filter(x => parseInt(x.id) !== id);
      persistir();
      return jsonResponse({ success: true, message: 'Hongo eliminado.' });
    }
  }

  // --- envios ---
  if (s0 === 'envios') {
    if (s1 === 'calcular') {
      const cp = parseInt(params.get('cp')) || 0;
      const t  = estado.envios.find(x => parseInt(x.activo) === 1 && cp >= x.cp_desde && cp <= x.cp_hasta);
      if (!t) return jsonResponse({ success: false, message: 'No hay tarifas para ese código postal.', data: null });
      const umbral   = parseFloat(estado.configuracion.envio_gratis_desde || 0);
      const subtotal = parseFloat(params.get('subtotal') || 0);
      const gratis   = umbral > 0 && subtotal >= umbral;
      return jsonResponse(ok({
        ...t,
        precio: gratis ? '0.00' : t.precio,
        bonificado: gratis,
        descripcion: gratis ? t.descripcion + ' (envío bonificado)' : t.descripcion,
      }));
    }
    if (!s1 && metodo === 'GET')  return jsonResponse(ok(estado.envios));
    if (!s1 && metodo === 'POST') {
      const nueva = {
        id: demoProximoId(estado.envios),
        descripcion: cuerpo.descripcion, cp_desde: parseInt(cuerpo.cp_desde),
        cp_hasta: parseInt(cuerpo.cp_hasta), precio: String(parseFloat(cuerpo.precio).toFixed(2)),
        activo: cuerpo.activo !== undefined ? parseInt(cuerpo.activo) : 1,
      };
      estado.envios.push(nueva); persistir();
      return jsonResponse(ok(nueva, { message: 'Tarifa creada.' }), 201);
    }
    if (id && metodo === 'PUT') {
      const t = estado.envios.find(x => parseInt(x.id) === id);
      if (!t) return error('Tarifa no encontrada.', 404);
      if (cuerpo.descripcion !== undefined) t.descripcion = cuerpo.descripcion;
      if (cuerpo.cp_desde !== undefined) t.cp_desde = parseInt(cuerpo.cp_desde);
      if (cuerpo.cp_hasta !== undefined) t.cp_hasta = parseInt(cuerpo.cp_hasta);
      if (cuerpo.precio   !== undefined) t.precio   = String(parseFloat(cuerpo.precio).toFixed(2));
      if (cuerpo.activo   !== undefined) t.activo   = parseInt(cuerpo.activo);
      persistir();
      return jsonResponse(ok(t, { message: 'Tarifa actualizada.' }));
    }
    if (id && metodo === 'DELETE') {
      estado.envios = estado.envios.filter(x => parseInt(x.id) !== id);
      persistir();
      return jsonResponse({ success: true, message: 'Tarifa eliminada.' });
    }
  }

  // --- pedidos ---
  if (s0 === 'pedidos') {
    if (!s1 && metodo === 'GET') {
      const filtro = params.get('estado');
      return jsonResponse(ok(estado.pedidos.filter(p => !filtro || p.estado === filtro)));
    }
    if (id && s2 === 'mails') return jsonResponse(ok([]));
    if (id && metodo === 'GET') {
      const p = estado.pedidos.find(x => parseInt(x.id) === id);
      return p ? jsonResponse(ok(p)) : error('Pedido no encontrado.', 404);
    }
    if (id && s2 === 'estado' && metodo === 'PUT') {
      const p = estado.pedidos.find(x => parseInt(x.id) === id);
      if (!p) return error('Pedido no encontrado.', 404);
      p.estado = cuerpo.estado;
      persistir();
      return jsonResponse(ok(p, { message: 'Estado actualizado.' }));
    }
    if (id && s2 === 'tracking' && metodo === 'PUT') {
      const p = estado.pedidos.find(x => parseInt(x.id) === id);
      if (!p) return error('Pedido no encontrado.', 404);
      Object.assign(p, {
        transporte: cuerpo.transporte || null,
        tracking_codigo: cuerpo.tracking_codigo || null,
        tracking_url: cuerpo.tracking_url || null,
      });
      persistir();
      return jsonResponse(ok(p, { message: 'Seguimiento guardado.' }));
    }
  }

  // --- usuarios ---
  if (s0 === 'usuarios') {
    if (!s1 && metodo === 'GET') return jsonResponse(ok(estado.usuarios));
    return error('En la demo no se administran usuarios: no hay sesiones reales.', 409);
  }

  // --- upload ---
  // El archivo llega como FormData; se reduce y se devuelve como data URI,
  // que es lo unico que puede persistir sin servidor.
  if (s0 === 'upload') {
    const archivo = cuerpo instanceof FormData ? cuerpo.get('imagen') : null;
    if (!archivo) return error('No se recibió ningún archivo.');
    if (!/^image\//.test(archivo.type)) return error('Solo se aceptan imágenes.');
    try {
      const url = await demoImagenAdataUri(archivo);
      return jsonResponse({ success: true, url });
    } catch (e) {
      return error('No se pudo procesar la imagen.', 500);
    }
  }

  return error(`Ruta '${ruta}' no encontrada en la demo.`, 404);
}

/**
 * Reduce la imagen antes de guardarla: localStorage tiene ~5 MB y una foto
 * de camara en base64 sola ya lo llena.
 */
function demoImagenAdataUri(archivo, maxLado = 700) {
  return new Promise((resolve, reject) => {
    const lector = new FileReader();
    lector.onerror = reject;
    lector.onload = () => {
      const img = new Image();
      img.onerror = reject;
      img.onload = () => {
        let { width, height } = img;
        if (width > maxLado || height > maxLado) {
          const escala = maxLado / Math.max(width, height);
          width  = Math.round(width  * escala);
          height = Math.round(height * escala);
        }
        const lienzo = document.createElement('canvas');
        lienzo.width = width; lienzo.height = height;
        lienzo.getContext('2d').drawImage(img, 0, 0, width, height);
        resolve(lienzo.toDataURL('image/jpeg', 0.75));
      };
      img.src = lector.result;
    };
    lector.readAsDataURL(archivo);
  });
}

function demoSlug(texto) {
  return String(texto).toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

// ---- Intercepcion de fetch ----

function demoInterceptarFetch() {
  const fetchOriginal = window.fetch.bind(window);

  window.fetch = async function (entrada, opciones = {}) {
    const url = typeof entrada === 'string' ? entrada : (entrada && entrada.url) || '';

    // Solo se intercepta la API; los assets y los JSON siguen su curso.
    if (!url.includes('/api/index.php')) {
      return fetchOriginal(entrada, opciones);
    }

    const despues = url.split('/api/index.php')[1] || '';
    const [ruta, query] = despues.split('?');
    const params = new URLSearchParams(query || '');
    const metodo = (opciones.method || 'GET').toUpperCase();

    let cuerpo = {};
    if (opciones.body instanceof FormData) {
      cuerpo = opciones.body;
    } else if (opciones.body && typeof opciones.body === 'string') {
      try { cuerpo = JSON.parse(opciones.body); } catch { cuerpo = {}; }
    }

    try {
      return await demoResponder(ruta, metodo, cuerpo, params);
    } catch (e) {
      console.error('demo:', e);
      return error('Error en la demo: ' + e.message, 500);
    }
  };
}

// ---- Aviso ----

function demoMostrarAviso() {
  if (document.getElementById('aviso-demo')) return;

  const barra = document.createElement('div');
  barra.id = 'aviso-demo';
  barra.style.cssText = [
    'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:9999',
    'background:#1F3D2E', 'color:#F5F1E8', 'font-family:var(--font)',
    'font-size:0.7rem', 'letter-spacing:0.04em', 'padding:0.45rem 1rem',
    'display:flex', 'align-items:center', 'justify-content:center', 'gap:1rem',
    'flex-wrap:wrap',
  ].join(';');

  const texto = document.createElement('span');
  texto.textContent = 'MODO DEMO — los cambios se guardan solo en este navegador. No hay servidor detrás.';

  const reiniciar = document.createElement('button');
  reiniciar.textContent = 'Reiniciar demo';
  reiniciar.style.cssText = [
    'background:transparent', 'color:#F5F1E8', 'border:1px solid rgba(245,241,232,0.5)',
    'border-radius:3px', 'padding:0.15rem 0.6rem', 'font-family:inherit',
    'font-size:0.65rem', 'cursor:pointer', 'letter-spacing:0.06em',
  ].join(';');
  reiniciar.addEventListener('click', () => {
    if (!confirm('¿Volver a los datos originales? Se pierde todo lo que cargaste en la demo.')) return;
    localStorage.removeItem(DEMO_KEY);
    location.reload();
  });

  barra.append(texto, reiniciar);
  document.body.appendChild(barra);
  document.body.style.paddingTop = '1.9rem';
}

// ---- Arranque ----
// Se ejecuta al cargarse el script, antes que auth.js haga su primer fetch.
// Se activa donde no hay PHP (GitHub Pages) o si se fuerza con ?demo=1,
// que sirve para probar la demo teniendo el backend real disponible.
const DEMO_FORZADO = new URLSearchParams(location.search).has('demo');
const DEMO_ACTIVO  = DEMO_FORZADO || (typeof PANEL_SIN_BACKEND !== 'undefined' && PANEL_SIN_BACKEND);

if (DEMO_ACTIVO) {
  demoInterceptarFetch();
  document.addEventListener('DOMContentLoaded', demoMostrarAviso);
}
