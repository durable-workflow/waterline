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
import Swal from 'sweetalert2';
import moment from 'moment-timezone';
import 'moment/locale/es';
import 'moment/locale/pt-br';
import 'moment/locale/uk';
import 'moment/locale/zh-cn';
import chartEnglish from 'apexcharts/dist/locales/en.json';
import chartSpanish from 'apexcharts/dist/locales/es.json';
import chartPortuguese from 'apexcharts/dist/locales/pt-br.json';
import chartUkrainian from 'apexcharts/dist/locales/uk.json';
import chartChinese from 'apexcharts/dist/locales/zh-cn.json';
import { readBootstrapConfig } from './bootstrap-config.mjs';
import WaterlineApp from './WaterlineApp.vue';
import { createWaterlineI18n, dialogLabels } from './localization.mjs';
import { createWaterlineDialogOptions } from './dialogs.mjs';
import { applyThemeStylesheet } from './theme.mjs';

import 'bootstrap';
import 'vue-json-pretty/lib/styles.css';

const mountElement = document.getElementById('waterline');
const waterline = readBootstrapConfig(mountElement);

if (mountElement && waterline) {
    window.Waterline = waterline;
    window.Popper = Popper;
    window.$ = window.jQuery = $;

    const token = document.head.querySelector('meta[name="csrf-token"]');

    axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

    if (token) {
        axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
    }

    const routerBasePath = waterline.basePath === '' ? '/' : `${waterline.basePath}/`;

    const router = createRouter({
        routes: Routes,
        history: createWebHistory(routerBasePath),
    });

    const app = createApp(WaterlineApp, { bootstrap: waterline });

    app.config.globalProperties.$http = axios.create();
    app.config.errorHandler = function (err, instance, info) {
        // eslint-disable-next-line no-console
        console.error('[Vue:errorHandler]', err, info);
    };

    app.use(router);
    const i18n = createWaterlineI18n(waterline.locale);
    app.use(i18n);
    const dateLocale = i18n.global.locale.value === 'zh-Hans' ? 'zh-cn' : i18n.global.locale.value.toLowerCase();
    moment.locale(dateLocale);
    window.Apex = {
        ...window.Apex,
        chart: { ...window.Apex?.chart, locales: [chartEnglish, chartSpanish, chartPortuguese, chartUkrainian, chartChinese], defaultLocale: dateLocale },
    };
    app.config.globalProperties.$dialog = function (options) {
        return Swal.fire(createWaterlineDialogOptions(this.$root.theme, {
            ...dialogLabels(i18n.global.t), ...options,
        }));
    };
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
    // Measure charts only after the user's selected theme has its real layout.
    applyThemeStylesheet(localStorage.getItem('waterline-theme') || 'dark')
        .catch(error => console.error('[Waterline:theme]', error))
        .finally(() => {
            app.mount(mountElement);
            mountElement.setAttribute('data-waterline-mounted', 'true');
        });
} else if (mountElement) {
    mountElement.removeAttribute('v-cloak');
    mountElement.replaceChildren();

    const message = document.createElement('div');
    message.className = 'alert alert-danger';
    message.setAttribute('role', 'alert');
    message.textContent = createWaterlineI18n(document.documentElement.lang).global.t('Waterline could not start because its page configuration is missing or invalid.');
    mountElement.appendChild(message);
}
