// ============================================================
// KABODHI — pagos.js
// Cuotas de Mercado Pago y datos de transferencia para la tienda.
//
// Las cuotas salen de la cuenta de Mercado Pago de la tienda (GET /mp/cuotas):
// si la tienda tiene "cuotas sin interes" activas, aca se muestran.
// ============================================================

const Pagos = {
  _cache: {},

  /** Cuotas para un monto: { max_sin_interes, opciones:[{cuotas, monto_cuota, total, sin_interes}] }. */
  async cuotas(monto) {
    monto = Math.round(parseFloat(monto) * 100) / 100;
    if (!monto || monto <= 0 || (typeof IS_STATIC !== 'undefined' && IS_STATIC)) {
      return { max_sin_interes: 0, opciones: [] };
    }
    if (Pagos._cache[monto]) return Pagos._cache[monto];

    Pagos._cache[monto] = (async () => {
      try {
        const res  = await fetch(API_URL + '/mp/cuotas?monto=' + encodeURIComponent(monto));
        const json = await res.json();
        return (json.success && json.data) ? json.data : { max_sin_interes: 0, opciones: [] };
      } catch {
        return { max_sin_interes: 0, opciones: [] };
      }
    })();
    return Pagos._cache[monto];
  },

  /** "6 cuotas sin interés de $ 4.816" o "" si no hay cuotas sin interés. */
  async textoSinInteres(monto) {
    const c = await Pagos.cuotas(monto);
    if (!c.max_sin_interes || c.max_sin_interes < 2) return '';
    const fmt = window.formatMoney || (v => '$' + v);
    const op  = c.opciones.find(o => o.cuotas === c.max_sin_interes);
    return op
      ? `${c.max_sin_interes} cuotas sin interés de ${fmt(op.monto_cuota)}`
      : `Hasta ${c.max_sin_interes} cuotas sin interés`;
  },

  /** Escribe el texto de cuotas en un elemento (queda vacio si no aplica). */
  async renderCuotas(el, monto) {
    if (!el) return;
    el.textContent = '';
    const texto = await Pagos.textoSinInteres(monto);
    if (texto) el.textContent = texto;
  },

  /** Configuracion de pagos de la tienda (espera a que cargue la config). */
  async config() {
    if (typeof configLista !== 'undefined') await configLista;
    const cfg = (typeof CONFIG_TIENDA !== 'undefined' && CONFIG_TIENDA) || {};
    const cbu   = (cfg.transferencia_cbu   || '').trim();
    const alias = (cfg.transferencia_alias || '').trim();
    return {
      // Si la API no manda el dato (demo estatica), se asume disponible.
      mp_disponible:   cfg.mp_disponible === undefined || String(cfg.mp_disponible) === '1',
      mp_cuotas_max:   parseInt(cfg.mp_cuotas_max || 12) || 12,
      mp_mostrar_cuotas: String(cfg.mp_mostrar_cuotas ?? '1') === '1',
      transferencia: {
        activa:        String(cfg.transferencia_activa || '0') === '1' && (cbu !== '' || alias !== ''),
        descuento:     parseFloat(cfg.transferencia_descuento || 0) || 0,
        titular:       cfg.transferencia_titular || '',
        banco:         cfg.transferencia_banco   || '',
        cbu, alias,
        cuit:          cfg.transferencia_cuit    || '',
        instrucciones: cfg.transferencia_instrucciones || '',
      },
    };
  },

  /** Bloque HTML con los datos bancarios (resultado del checkout y checkout). */
  bloqueTransferencia(t, total) {
    const fmt = window.formatMoney || (v => '$' + v);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fila = (label, valor, mono) => valor
      ? `<div class="transf-dato"><span>${label}</span><strong${mono ? ' class="transf-dato--mono"' : ''}>${esc(valor)}</strong></div>` : '';
    return `
      <div class="transf-box">
        ${total != null ? `<div class="transf-total">Total a transferir <strong>${fmt(total)}</strong></div>` : ''}
        ${fila('Titular', t.titular)}
        ${fila('Banco', t.banco)}
        ${fila('CBU / CVU', t.cbu, true)}
        ${fila('Alias', t.alias, true)}
        ${fila('CUIT / CUIL', t.cuit)}
        ${t.instrucciones ? `<p class="transf-instrucciones">${esc(t.instrucciones)}</p>` : ''}
      </div>`;
  },
};

window.Pagos = Pagos;
