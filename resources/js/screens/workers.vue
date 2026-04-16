<script type="text/ecmascript-6">
    import WorkerHealth from '../components/WorkerHealth.vue';

    export default {
        components: {
            WorkerHealth
        },

        mounted() {
            document.title = "Waterline - Workers";
        }
    }
</script>

<template>
    <div>
        <worker-health></worker-health>
    </div>
</template>
