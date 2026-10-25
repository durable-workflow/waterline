<template>
    <section class="wl-exception-source" :aria-label="$t('Source')">
        <b>{{ $t('Source') }}</b>
        <pre v-if="available" class="wl-exception-source__excerpt" tabindex="0" role="region" :aria-label="$t('Source')"><code><span
            v-for="(line, index) in lines" :key="index"
            class="wl-exception-source__line"
            :class="{ 'wl-exception-source__line--throw': lineNumber(index) === context.throw_line }"
            :aria-current="lineNumber(index) === context.throw_line ? 'true' : undefined"
            :data-line="lineNumber(index)"
        ><span class="wl-exception-source__number">{{ lineNumber(index) === context.throw_line ? '▶' : ' ' }} {{ lineNumber(index) }}</span><span>{{ line || ' ' }}</span></span></code></pre>
        <p v-else class="mb-3">{{ $t('Unavailable') }}</p>
        <template v-if="tracePreview">
            <b>{{ $t('Trace') }}</b>
            <pre class="wl-exception-source__trace" tabindex="0" role="region" :aria-label="$t('Trace')">{{ tracePreview }}</pre>
        </template>
    </section>
</template>

<script>
export default {
    props: {
        context: { type: Object, default: null },
        tracePreview: { type: String, default: '' },
    },
    computed: {
        available() {
            return this.context?.status === 'available'
                && typeof this.context.excerpt === 'string'
                && Number.isInteger(this.context.start_line) && this.context.start_line > 0
                && Number.isInteger(this.context.throw_line)
                && this.context.throw_line >= this.context.start_line
                && this.context.throw_line < this.context.start_line + this.lines.length
        },
        lines() {
            return typeof this.context?.excerpt === 'string' ? this.context.excerpt.split('\n') : []
        },
    },
    methods: {
        lineNumber(index) {
            return this.context.start_line + index
        },
    },
}
</script>
