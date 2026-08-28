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

    document.getElementById('f-nosotros-titulo').value = cfg.nosotros_titulo || '';
    document.getElementById('f-nosotros-texto').value  = cfg.nosotros_texto  || '';
    document.getElementById('f-instagram').value       = cfg.instagram_usuario || '';
    document.getElementById('f-direccion').value       = cfg.direccion         || '';

    renderEstado(cfg);
  } catch (err) {
    showToast(err.message, 'error');
  }
}

function renderEstado(cfg) {
  const el = document.getElementById('config-estado');
  if (!el) return;

  const umbral = parseFloat(cfg.envio_gratis_desde || 0);
  el.innerHTML = umbral > 0
    ? `Envío gratis activo a partir de <strong>${formatMoney(umbral)}</strong>.`
    : 'Envío gratis desactivado: siempre se cobra la tarifa del código postal.';
}

async function guardarConfig() {
  const btn = document.getElementById('btn-guardar');
  btn.disabled    = true;
  btn.textContent = 'Guardando...';

  const payload = {
    whatsapp_numero:    document.getElementById('f-whatsapp').value.trim(),
    contacto_email:     document.getElementById('f-email').value.trim(),
    envio_gratis_desde: document.getElementById('f-envio-gratis').value || '0',
    nosotros_titulo:    document.getElementById('f-nosotros-titulo').value.trim(),
    nosotros_texto:     document.getElementById('f-nosotros-texto').value.trim(),
    instagram_usuario:  document.getElementById('f-instagram').value.trim(),
    direccion:          document.getElementById('f-direccion').value.trim(),
  };

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

document.addEventListener('DOMContentLoaded', async () => {
  if (!await checkAuth()) return;
  fetchConfig();
  document.getElementById('btn-guardar')?.addEventListener('click', guardarConfig);
});
