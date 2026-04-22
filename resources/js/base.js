import moment from 'moment-timezone';

export default {
    computed: {
        Waterline() {
            return Waterline;
        },
    },

    methods: {
        /**
         * Format the given date with respect to timezone.
         */
        formatDate(unixTime) {
            return moment(unixTime * 1000).add(new Date().getTimezoneOffset() / 60);
        },

        /**
         * Format the given date with respect to timezone.
         */
        formatDateIso(date) {
            return moment(date).add(new Date().getTimezoneOffset() / 60);
        },

        /**
         * Extract the flow base name.
         */
        flowBaseName(name) {
            if (!name.includes('\\')) return name;

            var parts = name.split('\\');

            return parts[parts.length - 1];
        },

        /**
         * Autoload new entries in listing screens.
         */
        autoLoadNewEntries() {
            if (!this.autoLoadsNewEntries) {
                this.autoLoadsNewEntries = true;
                localStorage.autoLoadsNewEntries = 1;
                if (this.refreshStatsPeriodically) {
                    this.refreshStatsPeriodically();
                }
                if (this.refreshFlowsPeriodically) {
                    this.refreshFlowsPeriodically();
                }
            } else {
                this.autoLoadsNewEntries = false;
                localStorage.autoLoadsNewEntries = 0;
                if (this.timeout) {
                    clearTimeout(this.timeout);
                }
                if (this.interval) {
                    clearInterval(this.interval);
                }
            }
        },

        /**
         * Convert to human readable timestamp.
         */
        readableTimestamp(timestamp) {
            return this.formatDate(timestamp).format('YYYY-MM-DD HH:mm:ss');
        },

        /**
         * Convert to timestamp.
         */
        timestamp(timestamp) {
            return typeof timestamp === 'string' && timestamp.length > 0
                ? timestamp.replace('T', ' ').replace('Z', '')
                : '-';
        },
    },
};
