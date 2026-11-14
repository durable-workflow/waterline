<script>
import VersionUsageView from '../components/VersionUsageView.vue';
export default {
    components: {VersionUsageView},
    mounted() { document.title = this.$t('Waterline - Version usage'); },
};
</script>

<template><version-usage-view /></template>
