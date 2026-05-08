<template>
    <div class="payload-inspector">
        <div v-if="error" class="alert alert-warning mb-2">
            <small>{{ error }}</small>
        </div>

        <div v-else-if="decoded !== null">
            <div v-if="isCollapsible" class="mb-1">
                <button
                    class="btn btn-sm btn-link p-0 text-muted"
                    @click="collapsed = !collapsed"
                    :aria-expanded="!collapsed">
                    <span v-if="collapsed">▶ Show payload ({{ payloadSize }})</span>
                    <span v-else>▼ Hide payload</span>
                </button>
            </div>

            <div v-show="!collapsed" class="payload-content">
                <pre class="mb-0 p-2 bg-light border rounded"><code>{{ formattedPayload }}</code></pre>
            </div>
        </div>

        <div v-else-if="rawValue !== null" class="text-muted">
            <small>{{ rawValue }}</small>
        </div>

        <div v-else class="text-muted">
            <small>(empty)</small>
        </div>
    </div>
</template>

<script>
export default {
    name: 'PayloadInspector',

    props: {
        payload: {
            type: [Object, String, Array, null],
            default: null
        },
        // Auto-collapse if payload is larger than this (in characters)
        collapseThreshold: {
            type: Number,
            default: 500
        },
        // Start collapsed
        startCollapsed: {
            type: Boolean,
            default: null
        }
    },

    data() {
        return {
            decoded: null,
            error: null,
            collapsed: false,
            rawValue: null
        };
    },

    computed: {
        formattedPayload() {
            if (this.decoded === null) {
                return '';
            }

            try {
                return JSON.stringify(this.decoded, null, 2);
            } catch (e) {
                return String(this.decoded);
            }
        },

        payloadSize() {
            const size = this.formattedPayload.length;
            if (size < 1024) {
                return `${size} bytes`;
            } else if (size < 1024 * 1024) {
                return `${(size / 1024).toFixed(1)} KB`;
            } else {
                return `${(size / (1024 * 1024)).toFixed(1)} MB`;
            }
        },

        isCollapsible() {
            return this.formattedPayload.length > this.collapseThreshold;
        }
    },

    watch: {
        payload: {
            immediate: true,
            handler: 'decodePayload'
        }
    },

    methods: {
        decodePayload() {
            this.error = null;
            this.decoded = null;
            this.rawValue = null;

            if (this.payload === null || this.payload === undefined) {
                return;
            }

            // Handle envelope format: {codec, blob}.
            //
            // The run-detail backend pre-decodes payloads through the
            // workflow CommandPayloadPreview helper, so this component
            // normally receives plain values. The envelope branch is a
            // defensive fallback for surfaces that may forward a raw
            // {codec, blob} pair (e.g. external history exports).
            if (this.isEnvelope(this.payload)) {
                this.decodeEnvelope(this.payload);
                return;
            }

            // Handle direct objects/arrays
            if (typeof this.payload === 'object') {
                this.decoded = this.payload;
                this.updateCollapsedState();
                return;
            }

            // Handle string payloads
            if (typeof this.payload === 'string') {
                // Try to parse as JSON
                try {
                    this.decoded = JSON.parse(this.payload);
                    this.updateCollapsedState();
                } catch (e) {
                    // Not JSON, show as raw string
                    this.rawValue = this.payload;
                }
                return;
            }

            // Fallback: show as string
            this.rawValue = String(this.payload);
        },

        isEnvelope(value) {
            return (
                value !== null &&
                typeof value === 'object' &&
                'codec' in value &&
                'blob' in value
            );
        },

        decodeEnvelope(envelope) {
            const codec = envelope.codec;
            const blob = envelope.blob;

            if (codec === 'avro') {
                // Avro blobs are base64-encoded binary — client-side
                // decoding would require the apache-avro JS library, which
                // is not bundled. In normal flows the server pre-decodes
                // and we never see a raw avro envelope here; if one does
                // arrive, accept a pre-decoded object, then try JSON, then
                // surface the binary size.
                if (typeof blob === 'object' && blob !== null) {
                    this.decoded = blob;
                    this.updateCollapsedState();
                } else if (typeof blob === 'string') {
                    try {
                        this.decoded = JSON.parse(blob);
                        this.updateCollapsedState();
                    } catch (e) {
                        this.rawValue = `[Avro payload: ${blob.length} bytes]`;
                    }
                } else {
                    this.rawValue = blob;
                }
            } else {
                // Legacy PHP-only codecs (workflow-serializer-y,
                // workflow-serializer-base64) cannot be decoded in the
                // browser. v2 does not register any other codecs — JSON is
                // not a v2 codec — so unknown values fall through here and
                // render as the raw blob.
                this.rawValue = blob;
            }
        },

        updateCollapsedState() {
            if (this.startCollapsed !== null) {
                this.collapsed = this.startCollapsed;
            } else {
                this.collapsed = this.isCollapsible;
            }
        }
    }
};
</script>

<style scoped>
.payload-inspector pre {
    max-height: 400px;
    overflow: auto;
    font-size: 0.85rem;
}

.payload-inspector code {
    color: #333;
}

.payload-inspector .btn-link {
    text-decoration: none;
    font-size: 0.875rem;
}

.payload-inspector .btn-link:hover {
    text-decoration: underline;
}
</style>
