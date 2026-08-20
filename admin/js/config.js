const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
const APP_BASE   = isLocal ? '/hongos' : '';
const PAGES_BASE = isLocal ? '/hongos' : '';
const API_URL    = APP_BASE + '/api/index.php';
