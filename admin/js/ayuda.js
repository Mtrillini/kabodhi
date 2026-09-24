// ============================================================
// KABODHI Admin — ayuda.js
// Edita la pagina de Ayuda: los dos bloques de texto (que viven en
// configuracion) y las preguntas frecuentes (tabla faq).
// ============================================================

let preguntas = [];   // { id, pregunta, respuesta, activo }

// ---- Carga ----
async function cargar() {
  try {
    const [cfgRes, faqRes] = await Promise.all([
      fetch(API_URL + '/configuracion', { credentials: 'include' }),
      fetch(API_URL + '/faq?all=1',     { credentials: 'include' }),
    ]);
    const cfg = (await cfgRes.json()).data || {};
    const faq = (await faqRes.json()).data || [];

    document.getElementById('f-envios-titulo').value  = cfg.ayuda_envios_titulo  || '';
    document.getElementById('f-envios-texto').value   = cfg.ayuda_envios_texto   || '';
    document.getElementById('f-cambios-titulo').value = cfg.ayuda_cambios_titulo || '';
    document.getElementById('f-cambios-texto').value  = cfg.ayuda_cambios_texto  || '';
    document.getElementById('f-faq-titulo').value     = cfg.ayuda_faq_titulo     || '';

    preguntas = faq.map(f => ({
      id:        parseInt(f.id),
      pregunta:  f.pregunta  || '',
      respuesta: f.respuesta || '',
      activo:    parseInt(f.activo) === 1 ? 1 : 0,
    }));
    renderPreguntas();
  } catch (e) {
    showToast('No se pudo cargar el contenido de Ayuda.', 'error');
  }
}

// ---- Preguntas ----
// Se arman con el DOM y no con innerHTML: escHtml no escapa comillas y una
// pregunta con " romperia el atributo value.
function renderPreguntas() {
  const lista = document.getElementById('faq-lista');
  if (!lista) return;

  lista.innerHTML = '';
  if (!preguntas.length) {
    const vacio = document.createElement('p');
    vacio.style.cssText = 'font-size:0.75rem;color:var(--taupe);';
    vacio.textContent = 'Todavía no hay preguntas cargadas.';
    lista.appendChild(vacio);
    return;
  }
  preguntas.forEach((p, i) => lista.appendChild(filaPregunta(p, i)));
}

function filaPregunta(p, i) {
  const fila = document.createElement('div');
  fila.style.cssText = 'border:1px solid var(--champagne);border-radius:var(--radius);padding:0.9rem;';

  const cabecera = document.createElement('div');
  cabecera.style.cssText = 'display:flex;align-items:center;gap:0.6rem;margin-bottom:0.55rem;';

  const numero = document.createElement('span');
  numero.style.cssText = 'font-size:0.7rem;color:var(--taupe);min-width:18px;';
  numero.textContent = (i + 1) + '.';

  const pregunta = document.createElement('input');
  pregunta.type        = 'text';
  pregunta.className   = 'form-input';
  pregunta.placeholder = '¿Cómo tomo los productos?';
  pregunta.value       = p.pregunta;
  pregunta.style.flex  = '1';
  pregunta.addEventListener('input', () => { preguntas[i].pregunta = pregunta.value; });

  const subir = botonIcono('↑', 'Subir', () => mover(i, -1));
  const bajar = botonIcono('↓', 'Bajar', () => mover(i, 1));
  subir.disabled = i === 0;
  bajar.disabled = i === preguntas.length - 1;

  const borrar = botonIcono('✕', 'Borrar esta pregunta', () => quitar(i));
  borrar.style.color = '#c07b7b';

  cabecera.append(numero, pregunta, subir, bajar, borrar);

  const respuesta = document.createElement('textarea');
  respuesta.className   = 'form-textarea';
  respuesta.rows        = 3;
  respuesta.placeholder = 'Respuesta';
  respuesta.value       = p.respuesta;
  respuesta.addEventListener('input', () => { preguntas[i].respuesta = respuesta.value; });

  const visible = document.createElement('label');
  visible.style.cssText = 'display:flex;align-items:center;gap:0.4rem;font-size:0.7rem;color:var(--taupe);margin-top:0.5rem;';
  const check = document.createElement('input');
  check.type    = 'checkbox';
  check.checked = p.activo === 1;
  check.style.cssText = 'width:15px;height:15px;accent-color:var(--negro);';
  check.addEventListener('change', () => { preguntas[i].activo = check.checked ? 1 : 0; });
  visible.append(check, 'Visible en la página');

  fila.append(cabecera, respuesta, visible);
  return fila;
}

function botonIcono(texto, titulo, onClick) {
  const b = document.createElement('button');
  b.type        = 'button';
  b.textContent = texto;
  b.title       = titulo;
  b.style.cssText = 'background:none;border:none;cursor:pointer;font-size:0.85rem;color:var(--negro);padding:0.2rem 0.35rem;';
  b.addEventListener('click', onClick);
  return b;
}

function mover(i, delta) {
  const j = i + delta;
  if (j < 0 || j >= preguntas.length) return;
  [preguntas[i], preguntas[j]] = [preguntas[j], preguntas[i]];
  renderPreguntas();
}

function quitar(i) {
  const p = preguntas[i];
  if (p.pregunta && !confirm(`¿Borrar "${p.pregunta}"?`)) return;
  preguntas.splice(i, 1);
  renderPreguntas();
}

function addPregunta() {
  preguntas.push({ id: null, pregunta: '', respuesta: '', activo: 1 });
  renderPreguntas();
}
window.addPregunta = addPregunta;

// ---- Guardar ----
async function guardar() {
  const incompleta = preguntas.find(p => !p.pregunta.trim() || !p.respuesta.trim());
  if (incompleta) {
    showToast('Cada pregunta necesita su respuesta (o borrá la fila vacía).', 'error');
    return;
  }

  const btn = document.getElementById('btn-guardar');
  btn.disabled    = true;
  btn.textContent = 'Guardando...';

  try {
    const cfg = {
      ayuda_envios_titulo:  document.getElementById('f-envios-titulo').value.trim(),
      ayuda_envios_texto:   document.getElementById('f-envios-texto').value.trim(),
      ayuda_cambios_titulo: document.getElementById('f-cambios-titulo').value.trim(),
      ayuda_cambios_texto:  document.getElementById('f-cambios-texto').value.trim(),
      ayuda_faq_titulo:     document.getElementById('f-faq-titulo').value.trim(),
    };

    const resCfg = await fetch(API_URL + '/configuracion', {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(cfg),
    });
    const jsonCfg = await resCfg.json();
    if (!jsonCfg.success) throw new Error(jsonCfg.message || 'No se pudieron guardar los textos.');

    const resFaq = await fetch(API_URL + '/faq', {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ faq: preguntas }),
    });
    const jsonFaq = await resFaq.json();
    if (!jsonFaq.success) throw new Error(jsonFaq.message || 'No se pudieron guardar las preguntas.');

    showToast('Página de Ayuda actualizada.', 'success');
    cargar();   // vuelve con los ids de las preguntas nuevas
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Guardar cambios';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  cargar();
  document.getElementById('btn-guardar')?.addEventListener('click', guardar);
});
