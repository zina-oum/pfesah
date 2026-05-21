const IS_VERCEL = window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1';
const API_URL = IS_VERCEL ? '/' : '../../backend/';
const WS_URL = IS_VERCEL ? '' : '';
