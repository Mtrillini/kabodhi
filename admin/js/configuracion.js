// ============================================================
// KABODHI Admin — configuracion.js
// ============================================================

async function fetchConfig() {
  try {
    const res  = await fetch(API_URL + '/configuracion', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al cargar la configuración.');

    const cfg = json.data || {};
    document.getElementById('f-whatsapp').value     = cfg.whatsapp_numero    || '';
    document.getElementById('f-email').value        = cfg.contacto_email     || '';
    document.getElementById('f-envio-gratis').value = parseFloat(cfg.envio_gratis_desde || 0) || 0;

    // Pagos
    document.getElementById('f-mp-cuotas').value            = cfg.mp_cuotas_max || '12';
    document.getElementById('f-mp-descriptor').value        = cfg.mp_descriptor || '';
    document.getElementById('f-mp-mostrar-cuotas').checked  = String(cfg.mp_mostrar_cuotas ?? '1') === '1';
    document.getElementById('f-mp-excluir-efectivo').checked = String(cfg.mp_excluir_efectivo) === '1';
    document.getElementById('f-transf-activa').checked      = String(cfg.transferencia_activa) === '1';
    document.getElementById('f-transf-descuento').value     = cfg.transferencia_descuento || '0';
    document.getElementById('f-transf-titular').value       = cfg.transferencia_titular || '';
    document.getElementById('f-transf-banco').value         = cfg.transferencia_banco   || '';
    document.getElementById('f-transf-cbu').value           = cfg.transferencia_cbu     || '';
    document.getElementById('f-transf-alias').value         = cfg.transferencia_alias   || '';
    document.getElementById('f-transf-cuit').value          = cfg.transferencia_cuit    || '';
    document.getElementById('f-transf-instrucciones').value = cfg.transferencia_instrucciones || '';

    // Correo Argentino
    document.getElementById('f-envio-modo').value         = cfg.envio_modo || 'tabla';
    document.getElementById('f-correo-ambiente').value    = cfg.correo_ambiente || 'test';
    document.getElementById('f-correo-cp-origen').value   = cfg.correo_cp_origen || '';
    document.getElementById('f-correo-sucursal').checked  = String(cfg.correo_permite_sucursal) === '1';
    document.getElementById('f-correo-expreso').checked   = String(cfg.correo_permite_expreso)  === '1';
    document.getElementById('f-correo-tracking-url').value = cfg.correo_tracking_url || '';
    document.getElementById('f-peso-default').value  = cfg.envio_peso_default_gramos || '';
    document.getElementById('f-alto-default').value  = cfg.envio_alto_default_cm     || '';
    document.getElementById('f-ancho-default').value = cfg.envio_ancho_default_cm    || '';
    document.getElementById('f-largo-default').value = cfg.envio_largo_default_cm    || '';

    document.getElementById('f-nosotros-titulo').value = cfg.nosotros_titulo || '';
    document.getElementById('f-nosotros-texto').value  = cfg.nosotros_texto  || '';
    document.getElementById('f-instagram').value       = cfg.instagram_usuario || '';
    document.getElementById('f-direccion').value       = cfg.direccion         || '';

    // Banner de detalles: JSON array que se convierte a saltos de línea en el textarea.
    const bannerDetalles = cfg.banner_detalles || '';
    if (bannerDetalles) {
      try {
        const arr = JSON.parse(bannerDetalles);
        if (Array.isArray(arr)) {
          document.getElementById('f-banner-detalles').value = arr.join('\n');
        }
      } catch (e) {
        // Si no es JSON válido, mostrar tal cual.
        document.getElementById('f-banner-detalles').value = bannerDetalles;
      }
    } else {
      document.getElementById('f-banner-detalles').value = '';
    }

    renderEstado(cfg);
  } catch (err) {
    showToast(err.message, 'error');
  }
}

function renderEstado(cfg) {
  const el = document.getElementById('config-estado');
  if (!el) return;

  const umbral = parseFloat(cfg.envio_gratis_desde || 0);
  const lineas = [];
  lineas.push(umbral > 0
    ? `Envío gratis activo a partir de <strong>${formatMoney(umbral)}</strong>.`
    : 'Envío gratis desactivado: siempre se cobra el envío.');
  lineas.push(String(cfg.transferencia_activa) === '1'
    ? `Transferencia bancaria activa${parseFloat(cfg.transferencia_descuento || 0) > 0 ? ' con <strong>' + cfg.transferencia_descuento + '%</strong> de descuento' : ''}.`
    : 'Transferencia bancaria desactivada: solo se cobra por Mercado Pago.');
  lineas.push(cfg.envio_modo === 'correo'
    ? `Cotización con <strong>Correo Argentino</strong> (${cfg.correo_ambiente === 'prod' ? 'producción' : 'pruebas'}) desde el CP ${cfg.correo_cp_origen || '—'}, con la tabla de tarifas como respaldo.`
    : 'Cotización con la <strong>tabla de tarifas</strong> por código postal.');
  el.innerHTML = lineas.join('<br>');
}

async function guardarConfig() {
  const btn = document.getElementById('btn-guardar');
  btn.disabled    = true;
  btn.textContent = 'Guardando...';

  const payload = {
    whatsapp_numero:    document.getElementById('f-whatsapp').value.trim(),
    contacto_email:     document.getElementById('f-email').value.trim(),
    envio_gratis_desde: document.getElementById('f-envio-gratis').value || '0',

    mp_cuotas_max:        document.getElementById('f-mp-cuotas').value || '12',
    mp_descriptor:        document.getElementById('f-mp-descriptor').value.trim(),
    mp_mostrar_cuotas:    document.getElementById('f-mp-mostrar-cuotas').checked ? '1' : '0',
    mp_excluir_efectivo:  document.getElementById('f-mp-excluir-efectivo').checked ? '1' : '0',
    transferencia_activa:    document.getElementById('f-transf-activa').checked ? '1' : '0',
    transferencia_descuento: document.getElementById('f-transf-descuento').value || '0',
    transferencia_titular:   document.getElementById('f-transf-titular').value.trim(),
    transferencia_banco:     document.getElementById('f-transf-banco').value.trim(),
    transferencia_cbu:       document.getElementById('f-transf-cbu').value.trim(),
    transferencia_alias:     document.getElementById('f-transf-alias').value.trim(),
    transferencia_cuit:      document.getElementById('f-transf-cuit').value.trim(),
    transferencia_instrucciones: document.getElementById('f-transf-instrucciones').value.trim(),

    envio_modo:               document.getElementById('f-envio-modo').value,
    correo_ambiente:          document.getElementById('f-correo-ambiente').value,
    correo_cp_origen:         document.getElementById('f-correo-cp-origen').value.trim(),
    correo_permite_sucursal:  document.getElementById('f-correo-sucursal').checked ? '1' : '0',
    correo_permite_expreso:   document.getElementById('f-correo-expreso').checked  ? '1' : '0',
    correo_tracking_url:      document.getElementById('f-correo-tracking-url').value.trim(),
    envio_peso_default_gramos: document.getElementById('f-peso-default').value  || '300',
    envio_alto_default_cm:     document.getElementById('f-alto-default').value  || '10',
    envio_ancho_default_cm:    document.getElementById('f-ancho-default').value || '15',
    envio_largo_default_cm:    document.getElementById('f-largo-default').value || '20',

    nosotros_titulo:    document.getElementById('f-nosotros-titulo').value.trim(),
    nosotros_texto:     document.getElementById('f-nosotros-texto').value.trim(),
    instagram_usuario:  document.getElementById('f-instagram').value.trim(),
    direccion:          document.getElementById('f-direccion').value.trim(),
  };

  // Banner de detalles: convertir saltos de línea a JSON array.
  const bannerEl = document.getElementById('f-banner-detalles');
  if (bannerEl) {
    const bannerTextarea = bannerEl.value.trim();
    if (bannerTextarea !== '') {
      const lineas = bannerTextarea.split('\n').map(l => l.trim()).filter(l => l !== '');
      payload.banner_detalles = JSON.stringify(lineas);
    } else {
      payload.banner_detalles = '';
    }
  }

  try {
    const res  = await fetch(API_URL + '/configuracion', {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error al guardar.');

    // El backend normaliza el numero de WhatsApp: reflejamos lo que quedo guardado.
    const cfg = json.data || {};
    document.getElementById('f-whatsapp').value = cfg.whatsapp_numero || '';
    renderEstado(cfg);

    showToast('Configuración guardada.', 'success');
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Guardar cambios';
  }
}

/**
 * Pide token y customerId a la API de Correo con lo que hay en el .env y el
 * ambiente GUARDADO (si se cambio el select, primero hay que guardar).
 */
async function probarCorreo() {
  const btn    = document.getElementById('btn-probar-correo');
  const estado = document.getElementById('correo-estado');
  btn.disabled = true;
  estado.textContent = 'Conectando...';
  estado.style.color = 'var(--taupe)';

  try {
    const res  = await fetch(API_URL + '/envios/correo/probar', { method: 'POST', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'No se pudo conectar.');
    estado.textContent = '✓ ' + json.message;
    estado.style.color = '#3a7a3a';
  } catch (err) {
    estado.textContent = '✕ ' + err.message;
    estado.style.color = '#8b3a3a';
  } finally {
    btn.disabled = false;
  }
}

async function probarMP() {
  const btn    = document.getElementById('btn-probar-mp');
  const estado = document.getElementById('mp-estado');
  btn.disabled = true;
  estado.textContent = 'Conectando...';
  estado.style.color = 'var(--taupe)';
  try {
    const res  = await fetch(API_URL + '/mp/probar', { method: 'POST', credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'No se pudo conectar.');
    estado.textContent = '✓ ' + json.message;
    estado.style.color = '#3a7a3a';
  } catch (err) {
    estado.textContent = '✕ ' + err.message;
    estado.style.color = '#8b3a3a';
  } finally {
    btn.disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  if (!await checkAuth()) return;
  fetchConfig();
  document.getElementById('btn-guardar')?.addEventListener('click', guardarConfig);
  document.getElementById('btn-probar-correo')?.addEventListener('click', probarCorreo);
  document.getElementById('btn-probar-mp')?.addEventListener('click', probarMP);
});
