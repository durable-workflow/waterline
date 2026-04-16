import Vue from 'vue';
import Base from './base';
import axios from 'axios';
import Routes from './routes';
import VueRouter from 'vue-router';
import VueJsonPretty from 'vue-json-pretty';
import VueApexCharts from 'vue-apexcharts';
import { PrismEditor } from 'vue-prism-editor';

import 'vue-prism-editor/dist/prismeditor.min.css';

window.Popper = require('popper.js').default;

try {
    window.$ = window.jQuery = require('jquery');

    require('bootstrap');
} catch (e) {}

let token = document.head.querySelector('meta[name="csrf-token"]');

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

Vue.use(VueRouter);

Vue.prototype.$http = axios.create();

window.Waterline.basePath = '/' + window.Waterline.path;

let routerBasePath = window.Waterline.basePath + '/';

if (window.Waterline.path === '' || window.Waterline.path === '/') {
    routerBasePath = '/';
    window.Waterline.basePath = '';
}

const router = new VueRouter({
    routes: Routes,
    mode: 'history',
    base: routerBasePath,
});

Vue.use(VueApexCharts)

Vue.component('apexchart', VueApexCharts)
Vue.component('vue-json-pretty', VueJsonPretty);
Vue.component('PrismEditor', PrismEditor);

Vue.mixin(Base);

Vue.directive('tooltip', function (el, binding) {
    $(el).tooltip({
        title: binding.value,
        placement: binding.arg,
        trigger: 'hover',
    });
});

new Vue({
    el: '#waterline',

    router,

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

            theme: localStorage.getItem('waterline-theme') || 'light',
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
                const basePath = window.Waterline.basePath || '';
                const cssFile = this.theme === 'dark' ? 'app-dark.css' : 'app.css';
                link.href = `${basePath}/vendor/waterline/${cssFile}`;
            }
        }
    }
});
