<template>
    <pre
        v-bind="$attrs"
        class="prism-editor"
        :class="{ 'prism-editor--line-numbers': lineNumbers }"
    ><code v-html="highlightedCode"></code></pre>
</template>

<script>
export default {
    name: 'PrismEditor',

    inheritAttrs: false,

    props: {
        modelValue: {
            type: String,
            default: '',
        },
        highlight: {
            type: Function,
            default: null,
        },
        lineNumbers: {
            type: Boolean,
            default: false,
        },
    },

    computed: {
        highlightedCode() {
            const code = this.modelValue || '';

            if (this.highlight) {
                return this.highlight(code);
            }

            return code
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        },
    },
};
</script>

<style scoped>
.prism-editor {
    color: #f8f8f2;
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    line-height: 1.5;
    margin: 0;
    overflow: auto;
    padding: 1rem;
    tab-size: 4;
    white-space: pre;
}

.prism-editor--line-numbers {
    counter-reset: prism-line;
}
</style>
