<script type="text/ecmascript-6">
    import ServiceCatalogView from '../components/ServiceCatalogView.vue';

    export default {
        components: {
            ServiceCatalogView
        },

        mounted() {
            document.title = "Waterline - Services";
        }
    }
</script>

<template>
    <div>
        <service-catalog-view></service-catalog-view>
    </div>
</template>
