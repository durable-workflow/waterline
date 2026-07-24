import Dashboard from './screens/dashboard.vue';
import Workers from './screens/workers.vue';
import Schedules from './screens/schedules.vue';
import Services from './screens/services.vue';
import Flow from './screens/flows/flow.vue';
import Flows from './screens/flows/index.vue';

export default [
    { path: '/', redirect: '/dashboard' },

    {
        path: '/dashboard',
        name: 'dashboard',
        component: Dashboard,
    },

    {
        path: '/workers',
        name: 'workers',
        component: Workers,
    },

    {
        path: '/schedules',
        name: 'schedules',
        component: Schedules,
    },

    {
        path: '/services',
        name: 'services',
        component: Services,
    },

    {
        path: '/flows/instances/:instanceId',
        name: 'flow-detail',
        component: Flow,
    },

    {
        path: '/flows/instances/:instanceId/runs/:runId',
        name: 'flow-detail-run',
        component: Flow,
    },

    {
        path: '/running/:flowId',
        name: 'running-flows-preview',
        component: Flow,
    },

    {
        path: '/completed/:flowId',
        name: 'completed-flows-preview',
        component: Flow,
    },

    {
        path: '/failed/:flowId',
        name: 'failed-flows-preview',
        component: Flow,
    },

    {
        path: '/cancelled/:flowId',
        name: 'cancelled-flows-preview',
        component: Flow,
    },

    {
        path: '/terminated/:flowId',
        name: 'terminated-flows-preview',
        component: Flow,
    },

    {
        path: '/:type',
        name: 'flows',
        component: Flows,
    },
];
