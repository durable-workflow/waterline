<script type="text/ecmascript-6">
    import ScheduleView from '../components/ScheduleView.vue';

    export default {
        components: {
            ScheduleView
        },

        mounted() {
            document.title = "Waterline - Schedules";
        }
    }
</script>

<template>
    <div>
        <schedule-view></schedule-view>
    </div>
</template>
