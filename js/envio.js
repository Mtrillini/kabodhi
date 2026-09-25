// ============================================================
// KABODHI — envio.js
// Cotizacion de envio compartida entre el carrito y el checkout.
//
// La eleccion del cliente vive en localStorage bajo 'nuve_envio':
//   { cp, opcion_id, proveedor, tipo_entrega, producto, nombre, precio,
//     precio_lista, bonificado, plazo, requiere_sucursal,
//     sucursal_codigo?, sucursal_nombre?, provincia? }
// El servidor vuelve a cotizar al crear el pedido: de aca solo se manda el
// CP, la opcion elegida y la sucursal.
// ============================================================

const ENVIO_KEY = 'nuve_envio';

const Envio = {
  get() {
    try { return JSON.parse(localStorage.getItem(ENVIO_KEY)) || null; } catch { return null; }
  },

  set(datos) {
    localStorage.setItem(ENVIO_KEY, JSON.stringify(datos));
    window.dispatchEvent(new Event('envio-updated'));
  },

  clear() {
    localStorage.removeItem(ENVIO_KEY);
    window.dispatchEvent(new Event('envio-updated'));
  },

  /** Ultima cotizacion (todas las opciones), solo en memoria de la pagina. */
  _cotizacion: null,

  /**
   * Pide las opciones de envio para el carrito actual.
   * Devuelve { ok, opciones, aviso, mensaje }.
   */
  async cotizar(cp) {
    const carrito = window.Carrito ? window.Carrito.get() : { items: [] };
    // La variante va en la cotizacion: cada opcion puede pesar distinto. Un
    // combo no tiene id propio: el servidor lo abre en los productos que
    // eligio el cliente (ver PromoService), asi el peso de la cotizacion
    // coincide con lo que despues se cobra de verdad.
    const items = (carrito.items || []).map(i => i.esCombo ? {
      tipo: 'promo', promo_id: i.promo_id,
      picks: (i.picks || []).map(p => ({ producto_id: p.producto_id, variante_id: p.variante_id || null })),
    } : {
      id: i.id, variante_id: i.variante_id || null, cantidad: i.cantidad,
    });

    const res  = await fetch(API_URL + '/envios/cotizar', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ cp, items }),
    });
    const json = await res.json().catch(() => ({}));

    if (!res.ok || !json.success || !json.data || !json.data.opciones?.length) {
      Envio._cotizacion = null;
      return { ok: false, opciones: [], mensaje: json.message || 'No hay envíos disponibles para ese código postal.' };
    }

    Envio._cotizacion = json.data;
    return { ok: true, opciones: json.data.opciones, aviso: json.data.aviso || null, cp: json.data.cp };
  },

  /** Guarda una opcion cotizada como la elegida (conserva sucursal si sigue aplicando). */
  elegir(cp, opcion) {
    const previo = Envio.get() || {};
    const mismaSucursal = previo.opcion_id === opcion.id && previo.cp === cp;
    Envio.set({
      cp,
      opcion_id:         opcion.id,
      proveedor:         opcion.proveedor,
      tipo_entrega:      opcion.tipo_entrega,
      producto:          opcion.producto,
      nombre:            opcion.nombre,
      precio:            opcion.precio,
      precio_lista:      opcion.precio_lista,
      bonificado:        !!opcion.bonificado,
      plazo:             opcion.plazo || null,
      requiere_sucursal: !!opcion.requiere_sucursal,
      sucursal_codigo:   mismaSucursal ? previo.sucursal_codigo : null,
      sucursal_nombre:   mismaSucursal ? previo.sucursal_nombre : null,
      provincia:         previo.provincia || null,
      // Retiro en punto de encuentro: la direccion/horario que cargo el admin.
      info_especial:     opcion.info_especial || null,
    });
  },

  /** Texto corto para el resumen: "Correo Argentino Clásico — a domicilio (3 a 6 días hábiles)". */
  descripcion(envio) {
    if (!envio) return '';
    let t = envio.nombre || '';
    if (envio.plazo) t += ` (${envio.plazo})`;
    if (envio.bonificado) t += ' · bonificado';
    return t;
  },

  /**
   * Lista de opciones como radios. `onChange(opcion)` se llama al elegir.
   */
  renderOpciones(container, opciones, seleccionadaId, onChange) {
    const fmt = window.formatMoney || (v => '$' + v);
    container.innerHTML = opciones.map(o => `
      <label class="envio-opcion${o.id === seleccionadaId ? ' envio-opcion--activa' : ''}">
        <input type="radio" name="envio-opcion" value="${o.id}" ${o.id === seleccionadaId ? 'checked' : ''}>
        <span class="envio-opcion__texto">
          <span class="envio-opcion__nombre">${Envio._esc(o.nombre)}</span>
          ${o.plazo ? `<span class="envio-opcion__plazo">${Envio._esc(o.plazo)}</span>` : ''}
          ${o.requiere_sucursal ? `<span class="envio-opcion__plazo">Elegís la sucursal al finalizar la compra.</span>` : ''}
          ${o.info_especial ? `<span class="envio-opcion__plazo" style="white-space:pre-line;">${Envio._esc(o.info_especial)}</span>` : ''}
        </span>
        <span class="envio-opcion__precio">
          ${o.bonificado
            ? (o.precio_lista > 0
                ? `<s style="color:#999;font-weight:400;">${fmt(o.precio_lista)}</s>&nbsp;Gratis`
                : 'Gratis')
            : fmt(o.precio)}
        </span>
      </label>
    `).join('');

    container.querySelectorAll('input[name="envio-opcion"]').forEach(input => {
      input.addEventListener('change', () => {
        container.querySelectorAll('.envio-opcion').forEach(l => l.classList.remove('envio-opcion--activa'));
        input.closest('.envio-opcion').classList.add('envio-opcion--activa');
        const opcion = opciones.find(o => o.id === input.value);
        if (opcion) onChange(opcion);
      });
    });
  },

  _esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },
};

window.Envio = Envio;
