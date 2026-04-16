export default [
    { path: '/', redirect: '/dashboard' },

    {
        path: '/dashboard',
        name: 'dashboard',
        component: require('./screens/dashboard').default,
    },

    {
        path: '/workers',
        name: 'workers',
        component: require('./screens/workers').default,
    },

    {
        path: '/schedules',
        name: 'schedules',
        component: require('./screens/schedules').default,
    },

    {
        path: '/flows/instances/:instanceId',
        name: 'flow-detail',
        component: require('./screens/flows/flow').default,
    },

    {
        path: '/flows/instances/:instanceId/runs/:runId',
        name: 'flow-detail-run',
        component: require('./screens/flows/flow').default,
    },

    {
        path: '/running/:flowId',
        name: 'running-flows-preview',
        component: require('./screens/flows/flow').default,
    },

    {
        path: '/completed/:flowId',
        name: 'completed-flows-preview',
        component: require('./screens/flows/flow').default,
    },

    {
        path: '/failed/:flowId',
        name: 'failed-flows-preview',
        component: require('./screens/flows/flow').default,
    },

    {
        path: '/cancelled/:flowId',
        name: 'cancelled-flows-preview',
        component: require('./screens/flows/flow').default,
    },

    {
        path: '/terminated/:flowId',
        name: 'terminated-flows-preview',
        component: require('./screens/flows/flow').default,
    },

    {
        path: '/:type',
        name: 'flows',
        component: require('./screens/flows/index').default,
    },
];
