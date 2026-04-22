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
            if (typeof name !== 'string' || name.length === 0) return name;
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

        /**
         * Format a millisecond duration with consistent precision so short
         * runs keep seconds resolution: sub-second shows `<1s`, seconds show
         * `Ns`, minutes show `Nm SSs` (or `Nm` when seconds are zero), hours
         * and days fall through to `Nh MMm` / `Nd HHh` respectively.
         */
        formatDuration(ms) {
            if (!ms || Number.isNaN(ms)) return '-';

            const total = Math.max(0, Math.floor(Number(ms)));
            if (total === 0) return '-';
            if (total < 1000) return '<1s';

            const totalSeconds = Math.floor(total / 1000);
            if (totalSeconds < 60) return totalSeconds + 's';

            const minutes = Math.floor(totalSeconds / 60);
            const remainingSeconds = totalSeconds % 60;
            if (minutes < 60) {
                return remainingSeconds > 0
                    ? minutes + 'm ' + String(remainingSeconds).padStart(2, '0') + 's'
                    : minutes + 'm';
            }

            const hours = Math.floor(minutes / 60);
            const remainingMinutes = minutes % 60;
            if (hours < 24) {
                return remainingMinutes > 0
                    ? hours + 'h ' + String(remainingMinutes).padStart(2, '0') + 'm'
                    : hours + 'h';
            }

            const days = Math.floor(hours / 24);
            const remainingHours = hours % 24;
            return remainingHours > 0
                ? days + 'd ' + String(remainingHours).padStart(2, '0') + 'h'
                : days + 'd';
        },

        /**
         * Format the duration between two timestamps using `formatDuration`.
         */
        durationBetween(start, end) {
            if (!start || !end) return '-';

            const startMs = new Date(start).getTime();
            const endMs = new Date(end).getTime();
            if (Number.isNaN(startMs) || Number.isNaN(endMs)) return '-';

            return this.formatDuration(Math.max(0, endMs - startMs));
        },
    },
};
