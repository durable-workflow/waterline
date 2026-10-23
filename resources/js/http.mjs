import axios from 'axios';
import { browserTimeoutMilliseconds } from './request-budget.mjs';

// Every Waterline view shares this instance without changing a host app's Axios.
const http = axios.create();

export function configureWaterlineHttp(bootstrap, csrfToken) {
    http.defaults.timeout = browserTimeoutMilliseconds(bootstrap);
    http.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    if (csrfToken) {
        http.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
    } else {
        delete http.defaults.headers.common['X-CSRF-TOKEN'];
    }
    return http;
}

export default http;
