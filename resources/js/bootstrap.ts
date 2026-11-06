import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Sister sites can set XSRF-TOKEN on the parent domain, so this site has its own cookie.
window.axios.defaults.xsrfCookieName = 'PING-CHAT-XSRF';
