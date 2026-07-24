import { createApp } from 'vue';
import Base from './base';
import axios from 'axios';
import Routes from './routes';
import { createRouter, createWebHistory } from 'vue-router';
import VueJsonPretty from 'vue-json-pretty';
import VueApexCharts from 'vue3-apexcharts';
import PrismEditor from './components/PrismEditor.vue';
import ErrorBoundary from './components/ErrorBoundary.vue';
import Popper from 'popper.js';
import $ from 'jquery';

import 'bootstrap';
import 'vue-json-pretty/lib/styles.css';

window.Popper = Popper;
window.$ = window.jQuery = $;

let token = document.head.querySelector('meta[name="csrf-token"]');

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

window.Waterline.basePath = '/' + window.Waterline.path;

let routerBasePath = window.Waterline.basePath + '/';

if (window.Waterline.path === '' || window.Waterline.path === '/') {
    routerBasePath = '/';
    window.Waterline.basePath = '';
}

const router = createRouter({
    routes: Routes,
    history: createWebHistory(routerBasePath),
});

const app = createApp({
    data() {
        return {
            alert: {
                type: null,
                autoClose: 0,
                message: '',
                confirmationProceed: null,
                confirmationCancel: null,
            },

            autoLoadsNewEntries: localStorage.autoLoadsNewEntries === '1',

            theme: localStorage.getItem('waterline-theme') || 'dark',
        };
    },

    mounted() {
        this.applyTheme();
    },

    methods: {
        toggleTheme() {
            this.theme = this.theme === 'light' ? 'dark' : 'light';
            localStorage.setItem('waterline-theme', this.theme);
            this.applyTheme();
        },

        applyTheme() {
            const link = document.getElementById('app-stylesheet');
            if (link) {
                const cssFile = this.theme === 'dark' ? 'app-dark.css' : 'app.css';
                link.href = `/vendor/waterline/${cssFile}`;
            }
        }
    }
});

app.config.globalProperties.$http = axios.create();
app.config.errorHandler = function (err, instance, info) {
    // eslint-disable-next-line no-console
    console.error('[Vue:errorHandler]', err, info);
};

app.use(router);
app.component('apexchart', VueApexCharts);
app.component('vue-json-pretty', VueJsonPretty);
app.component('PrismEditor', PrismEditor);
app.component('error-boundary', ErrorBoundary);
app.mixin(Base);
app.directive('tooltip', function (el, binding) {
    $(el).tooltip({
        title: binding.value,
        placement: binding.arg,
        trigger: 'hover',
    });
});
app.mount('#waterline');
