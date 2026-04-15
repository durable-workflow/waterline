<template>
    <div class="search-attribute-renderer">
        <div v-if="hasAttributes" class="search-attributes">
            <div v-for="(value, key) in attributes" :key="key" class="attribute-item">
                <span class="attribute-key">{{ key }}</span>:
                <span class="attribute-value" :class="valueClass(value)">
                    {{ formatValue(value) }}
                </span>
            </div>
        </div>
        <div v-else class="text-muted small">
            (no search attributes)
        </div>
    </div>
</template>

<script>
export default {
    name: 'SearchAttributeRenderer',

    props: {
        attributes: {
            type: [Object, null],
            default: null
        }
    },

    computed: {
        hasAttributes() {
            return this.attributes && Object.keys(this.attributes).length > 0;
        }
    },

    methods: {
        formatValue(value) {
            if (value === null) return 'null';
            if (value === undefined) return 'undefined';
            if (typeof value === 'boolean') return value ? 'true' : 'false';
            if (typeof value === 'number') return value.toString();
            if (typeof value === 'string') return value;
            if (Array.isArray(value)) return `[${value.length} items]`;
            if (typeof value === 'object') return JSON.stringify(value);
            return String(value);
        },

        valueClass(value) {
            if (typeof value === 'boolean') return 'value-boolean';
            if (typeof value === 'number') return 'value-number';
            if (typeof value === 'string') return 'value-string';
            if (value === null) return 'value-null';
            return 'value-object';
        }
    }
};
</script>

<style scoped>
.search-attribute-renderer {
    font-size: 0.9rem;
}

.search-attributes {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.attribute-item {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 0.25rem;
    padding: 0.25rem 0.5rem;
}

.attribute-key {
    font-weight: 600;
    color: #495057;
}

.attribute-value {
    font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, monospace;
    font-size: 0.875em;
}

.value-string {
    color: #28a745;
}

.value-number {
    color: #007bff;
}

.value-boolean {
    color: #6f42c1;
}

.value-null {
    color: #6c757d;
    font-style: italic;
}

.value-object {
    color: #fd7e14;
}
</style>
