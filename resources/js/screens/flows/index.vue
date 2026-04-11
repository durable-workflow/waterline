<script type="text/ecmascript-6">
    import FlowRow from './flow-row';
    import Swal from 'sweetalert2';

    const fallbackVisibilityFilterDefinition = {
        fields: {
            instance_id: { label: 'Instance ID', type: 'string', input: 'text', operator: 'exact', order: 0, query_parameter: 'instance_id' },
            run_id: { label: 'Run ID', type: 'string', input: 'text', operator: 'exact', order: 1, query_parameter: 'run_id' },
            workflow_type: { label: 'Workflow Type', type: 'string', input: 'text', operator: 'exact', order: 2, query_parameter: 'workflow_type' },
            business_key: { label: 'Business Key', type: 'string', input: 'text', operator: 'exact', order: 3, query_parameter: 'business_key' },
            compatibility: { label: 'Compatibility', type: 'string', input: 'text', operator: 'exact', order: 4, query_parameter: 'compatibility' },
            queue: { label: 'Queue', type: 'string', input: 'text', operator: 'exact', order: 5, query_parameter: 'queue' },
            connection: { label: 'Connection', type: 'string', input: 'text', operator: 'exact', order: 6, query_parameter: 'connection' },
            status: {
                label: 'Status',
                type: 'string',
                input: 'select',
                operator: 'exact',
                order: 7,
                query_parameter: 'status',
                options: [
                    { label: 'Pending', value: 'pending' },
                    { label: 'Running', value: 'running' },
                    { label: 'Waiting', value: 'waiting' },
                    { label: 'Cancelled', value: 'cancelled' },
                    { label: 'Terminated', value: 'terminated' },
                    { label: 'Completed', value: 'completed' },
                    { label: 'Failed', value: 'failed' },
                ],
            },
            status_bucket: {
                label: 'Status Bucket',
                type: 'string',
                input: 'select',
                operator: 'exact',
                order: 8,
                query_parameter: 'status_bucket',
                options: [
                    { label: 'Running', value: 'running' },
                    { label: 'Completed', value: 'completed' },
                    { label: 'Failed', value: 'failed' },
                ],
            },
            closed_reason: {
                label: 'Closed Reason',
                type: 'string',
                input: 'select',
                operator: 'exact',
                order: 9,
                query_parameter: 'closed_reason',
                options: [
                    { label: 'Completed', value: 'completed' },
                    { label: 'Failed', value: 'failed' },
                    { label: 'Cancelled', value: 'cancelled' },
                    { label: 'Terminated', value: 'terminated' },
                    { label: 'Continued', value: 'continued' },
                ],
            },
            wait_kind: {
                label: 'Wait Kind',
                type: 'string',
                input: 'select',
                operator: 'exact',
                order: 10,
                query_parameter: 'wait_kind',
                options: [
                    { label: 'Activity', value: 'activity' },
                    { label: 'Update', value: 'update' },
                    { label: 'Signal', value: 'signal' },
                    { label: 'Timer', value: 'timer' },
                    { label: 'Condition', value: 'condition' },
                    { label: 'Workflow Task', value: 'workflow-task' },
                    { label: 'Child', value: 'child' },
                ],
            },
            liveness_state: { label: 'Liveness State', type: 'string', input: 'text', operator: 'exact', order: 11, query_parameter: 'liveness_state' },
            repair_blocked_reason: {
                label: 'Repair Blocked Reason',
                type: 'string',
                input: 'select',
                operator: 'exact',
                order: 12,
                query_parameter: 'repair_blocked_reason',
                options: [
                    { label: 'Replay Blocked', value: 'unsupported_history' },
                    { label: 'Compat Blocked', value: 'waiting_for_compatible_worker' },
                    { label: 'Selected Run Not Current', value: 'selected_run_not_current' },
                    { label: 'Run Closed', value: 'run_closed' },
                    { label: 'Repair Not Needed', value: 'repair_not_needed' },
                ],
            },
            is_current_run: {
                label: 'Current Run',
                type: 'boolean',
                input: 'boolean_select',
                operator: 'exact',
                order: 13,
                query_parameter: 'is_current_run',
                options: [
                    { label: 'Yes', value: true },
                    { label: 'No', value: false },
                ],
            },
            continue_as_new_recommended: {
                label: 'Continue As New Recommended',
                type: 'boolean',
                input: 'boolean_select',
                operator: 'exact',
                order: 14,
                query_parameter: 'continue_as_new_recommended',
                options: [
                    { label: 'Yes', value: true },
                    { label: 'No', value: false },
                ],
            },
            archived: {
                label: 'Archived',
                type: 'boolean',
                input: 'boolean_select',
                operator: 'exact',
                order: 15,
                query_parameter: 'archived',
                options: [
                    { label: 'Yes', value: true },
                    { label: 'No', value: false },
                ],
            },
            is_terminal: {
                label: 'Terminal',
                type: 'boolean',
                input: 'boolean_select',
                operator: 'exact',
                order: 16,
                query_parameter: 'is_terminal',
                options: [
                    { label: 'Yes', value: true },
                    { label: 'No', value: false },
                ],
            },
        },
        labels: {
            label: 'Labels',
            type: 'map<string,string>',
            input: 'key_value_textarea',
            operator: 'exact',
            query_parameters: ['label[key]', 'labels[key]'],
            key_pattern: '^[A-Za-z0-9_.:-]{1,64}$',
            key_value_separator: '=',
            placeholder: 'tenant=acme\nregion=us-east',
            help: 'One exact-match label per line in key=value format.',
        },
    }

    export default {
        /**
         * The component's data.
         */
        data() {
            return {
                ready: false,
                loadingNewEntries: false,
                hasNewEntries: false,
                page: 1,
                totalPages: 1,
                flows: [],
                savedViews: [],
                savedViewsEnabled: false,
                selectedSavedView: null,
                visibilityFilters: null,
                filterDefinition: null,
            };
        },

        /**
         * Components
         */
        components: {
            FlowRow,
        },

        computed: {
            selectedCustomView() {
                if (!this.selectedSavedView) {
                    return null
                }

                return this.savedViews.find((view) => view.id === this.selectedSavedView && !view.system) || null
            },

            appliedFilterEntries() {
                const applied = this.visibilityFilters && this.visibilityFilters.applied
                    ? this.visibilityFilters.applied
                    : this.currentFilterPayload()
                const entries = []

                this.visibilityFieldEntries().forEach(([field]) => {
                    if (applied[field] === undefined || applied[field] === null || applied[field] === '') {
                        return
                    }

                    entries.push({
                        key: field,
                        label: this.filterFieldLabel(field),
                        value: this.formatAppliedFilterValue(field, applied[field]),
                    })
                })

                Object.entries(applied.labels || {}).forEach(([key, value]) => {
                    entries.push({
                        key: 'label:' + key,
                        label: 'Label',
                        value: key + '=' + value,
                    })
                })

                return entries
            },

            hasActiveFilters() {
                return this.appliedFilterEntries.length > 0 || !!this.selectedSavedView
            },
        },

        /**
         * Prepare the component.
         */
        mounted() {
            this.handleRouteChange();
            this.refreshFlowsPeriodically();
        },

        /**
         * Clean after the component is destroyed.
         */
        destroyed() {
            clearInterval(this.interval);
        },


        /**
         * Watch these properties for changes.
         */
        watch: {
            '$route'() {
                this.handleRouteChange();
            }
        },


        methods: {
            async handleRouteChange() {
                this.updatePageTitle();
                this.page = 1;

                await this.loadSavedViews();

                if (await this.normalizeRouteViewQuery()) {
                    return;
                }

                await this.loadFlows();
            },

            /**
             * Load the flows of the given tag.
             */
            loadFlows(page = 1, refreshing = false) {
                if (!refreshing) {
                    this.ready = false;
                }

                return this.$http.get(Waterline.basePath + '/api/flows/' + this.$route.params.type + '?' + this.apiQueryString(page))
                    .then(response => {
                        this.visibilityFilters = response.data.visibility_filters || null;
                        this.filterDefinition = response.data.visibility_filters && response.data.visibility_filters.definition
                            ? response.data.visibility_filters.definition
                            : this.filterDefinition

                        const incomingFirst = _.first(response.data.data);
                        const currentFirst = _.first(this.flows);

                        if (!this.$root.autoLoadsNewEntries && refreshing && this.flows.length && incomingFirst
                            && this.flowCursor(incomingFirst) !== this.flowCursor(currentFirst)) {
                            this.hasNewEntries = true;
                        } else {
                            this.flows = response.data.data;

                            this.totalPages = response.data.last_page;
                        }

                        this.ready = true;
                    })
                    .catch(() => {
                        if (!refreshing) {
                            this.flows = [];
                            this.totalPages = 1;
                            this.visibilityFilters = null;
                        }

                        this.ready = true;
                    });
            },

            loadSavedViews() {
                this.selectedSavedView = this.$route.query.view || null;

                return this.$http.get(Waterline.basePath + '/api/saved-views?bucket=' + encodeURIComponent(this.$route.params.type))
                    .then(response => {
                        const views = response.data.data || [];

                        this.savedViewsEnabled = views.some((view) => view.system === true);
                        this.savedViews = views.filter((view) => !view.system);
                        this.filterDefinition = response.data.filter_definition || this.filterDefinition

                        if (this.selectedSavedView && !this.savedViews.find((view) => view.id === this.selectedSavedView)) {
                            this.selectedSavedView = null;
                        }
                    })
                    .catch(() => {
                        this.savedViews = [];
                        this.savedViewsEnabled = false;
                        this.selectedSavedView = null;
                    });
            },

            normalizeRouteViewValue() {
                return this.selectedSavedView || null
            },

            async normalizeRouteViewQuery() {
                const routeView = typeof this.$route.query.view === 'string' && this.$route.query.view.length > 0
                    ? this.$route.query.view
                    : null
                const normalizedView = this.normalizeRouteViewValue()

                if (routeView === normalizedView) {
                    return false
                }

                const query = {...this.$route.query}

                if (normalizedView) {
                    query.view = normalizedView
                } else {
                    delete query.view
                }

                await this.$router.replace({
                    name: this.$route.name,
                    params: this.$route.params,
                    query,
                })

                return true
            },

            apiQueryString(page) {
                const params = new URLSearchParams();

                params.set('page', page);

                Object.entries(this.$route.query || {}).forEach(([key, value]) => {
                    if (key === 'page' || value === undefined || value === null || value === '') {
                        return;
                    }

                    if (Array.isArray(value)) {
                        value.forEach((entry) => params.append(key, entry));

                        return;
                    }

                    if (typeof value === 'object') {
                        Object.entries(value).forEach(([childKey, childValue]) => {
                            if (childValue !== undefined && childValue !== null && childValue !== '') {
                                params.append(key + '[' + childKey + ']', childValue);
                            }
                        });

                        return;
                    }

                    params.set(key, value);
                });

                return params.toString();
            },

            selectSavedView() {
                const query = {...this.$route.query};
                const selectedView = this.normalizeRouteViewValue();

                if (selectedView) {
                    query.view = selectedView;
                } else {
                    delete query.view;
                }

                this.$router.push({
                    name: this.$route.name,
                    params: this.$route.params,
                    query,
                });
            },

            currentFilterPayload() {
                const filters = {};
                const labels = {};

                this.visibilityFieldEntries().forEach(([field]) => {
                    const value = this.normalizeFilterValue(field, this.$route.query[field])

                    if (value !== undefined) {
                        filters[field] = value
                    }
                })

                Object.entries(this.$route.query || {}).forEach(([key, value]) => {
                    const match = key.match(this.labelQueryParameterPattern());

                    if (match && typeof value === 'string' && value.length > 0) {
                        labels[match[1]] = value;
                    }
                });

                ['label', 'labels'].forEach((key) => {
                    const value = this.$route.query[key];

                    if (!value || typeof value !== 'object' || Array.isArray(value)) {
                        return;
                    }

                    Object.entries(value).forEach(([labelKey, labelValue]) => {
                        if (this.labelKeyRegExp().test(labelKey) && typeof labelValue === 'string' && labelValue.length > 0) {
                            labels[labelKey] = labelValue;
                        }
                    });
                });

                if (Object.keys(labels).length > 0) {
                    filters.labels = labels;
                }

                return filters;
            },

            visibilityFilterContract() {
                return this.filterDefinition
                    || (this.visibilityFilters && this.visibilityFilters.definition)
                    || fallbackVisibilityFilterDefinition
            },

            visibilityFieldEntries() {
                return Object.entries(this.visibilityFilterContract().fields || {})
                    .sort(([, left], [, right]) => {
                        const leftOrder = typeof left.order === 'number' ? left.order : Number.MAX_SAFE_INTEGER
                        const rightOrder = typeof right.order === 'number' ? right.order : Number.MAX_SAFE_INTEGER

                        return leftOrder - rightOrder
                    })
            },

            visibilityFieldNames() {
                return this.visibilityFieldEntries().map(([field]) => field)
            },

            visibilityFieldDefinition(field) {
                return this.visibilityFilterContract().fields[field] || null
            },

            visibilityLabelsDefinition() {
                return this.visibilityFilterContract().labels || fallbackVisibilityFilterDefinition.labels
            },

            labelKeyPattern() {
                return this.visibilityLabelsDefinition().key_pattern || '^[A-Za-z0-9_.:-]{1,64}$'
            },

            labelKeyRegExp() {
                return new RegExp(this.labelKeyPattern())
            },

            labelQueryParameterPattern() {
                const keyPattern = this.labelKeyPattern()
                    .replace(/^\^/, '')
                    .replace(/\$$/, '')

                return new RegExp(`^labels?\\[(${keyPattern})\\]$`)
            },

            filterFieldLabel(field) {
                const definition = this.visibilityFieldDefinition(field)

                return definition && definition.label
                    ? definition.label
                    : field
            },

            optionValueString(value) {
                if (value === true) {
                    return 'true'
                }

                if (value === false) {
                    return 'false'
                }

                return value === undefined || value === null
                    ? ''
                    : String(value)
            },

            fieldOptions(field, selectedValue = '') {
                const definition = this.visibilityFieldDefinition(field)
                const options = definition && Array.isArray(definition.options)
                    ? definition.options.map((option) => ({...option}))
                    : []
                const normalizedSelected = this.optionValueString(selectedValue)

                if (!normalizedSelected) {
                    return options
                }

                if (!options.find((option) => this.optionValueString(option.value) === normalizedSelected)) {
                    options.push({
                        label: normalizedSelected,
                        value: normalizedSelected,
                    })
                }

                return options
            },

            optionLabel(field, value) {
                const normalizedValue = this.optionValueString(value)

                if (!normalizedValue) {
                    return value
                }

                const match = this.fieldOptions(field, value)
                    .find((option) => this.optionValueString(option.value) === normalizedValue)

                return match && match.label
                    ? match.label
                    : value
            },

            normalizeFilterValue(field, value) {
                const definition = this.visibilityFieldDefinition(field)

                if (!definition) {
                    return undefined
                }

                if (definition.type === 'boolean') {
                    return this.parseBooleanFilterValue(value)
                }

                if (typeof value !== 'string') {
                    return undefined
                }

                const normalized = value.trim()

                return normalized.length > 0
                    ? normalized
                    : undefined
            },

            parseBooleanFilterValue(value) {
                if (value === true || value === 1) {
                    return true
                }

                if (value === false || value === 0) {
                    return false
                }

                if (typeof value !== 'string') {
                    return undefined
                }

                switch (value.trim().toLowerCase()) {
                    case '1':
                    case 'true':
                    case 'yes':
                        return true
                    case '0':
                    case 'false':
                    case 'no':
                        return false
                    default:
                        return undefined
                }
            },

            formatAppliedFilterValue(field, value) {
                const definition = this.visibilityFieldDefinition(field)

                if (definition && Array.isArray(definition.options)) {
                    return this.optionLabel(field, value)
                }

                return value
            },

            effectiveFilterPayload() {
                const applied = this.visibilityFilters && this.visibilityFilters.applied
                    ? this.visibilityFilters.applied
                    : this.currentFilterPayload()

                return {
                    ...applied,
                    labels: applied.labels
                        ? {...applied.labels}
                        : undefined,
                }
            },

            filterValue(field, filters) {
                const value = filters[field]

                return value === undefined || value === null
                    ? ''
                    : String(value)
            },

            fieldInputId(field) {
                return `waterline-filter-${field.replace(/_/g, '-')}`
            },

            labelsText(filters) {
                return Object.entries(filters.labels || {})
                    .map(([key, value]) => key + '=' + value)
                    .join('\n')
            },

            escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
            },

            filterEditorHtml(filters) {
                const textInput = (id, label, value) => `
                    <label class="d-block text-left mb-1" for="${id}">${label}</label>
                    <input id="${id}" class="swal2-input" value="${this.escapeHtml(value)}">
                `
                const selectInput = (id, label, value, options) => `
                    <label class="d-block text-left mb-1" for="${id}">${label}</label>
                    <select id="${id}" class="swal2-input">
                        <option value="" ${value === '' ? 'selected' : ''}>Any</option>
                        ${options.map((option) => `
                            <option value="${this.escapeHtml(this.optionValueString(option.value))}" ${value === this.optionValueString(option.value) ? 'selected' : ''}>${this.escapeHtml(option.label)}</option>
                        `).join('')}
                    </select>
                `
                const labelsDefinition = this.visibilityLabelsDefinition()
                const labelPlaceholder = this.escapeHtml(labelsDefinition.placeholder || 'tenant=acme\nregion=us-east')
                    .replace(/\n/g, '&#10;')
                const fieldsHtml = this.visibilityFieldEntries()
                    .map(([field, definition]) => {
                        const id = this.fieldInputId(field)
                        const label = this.escapeHtml(definition.label || field)
                        const value = this.filterValue(field, filters)

                        if (definition.input === 'boolean_select' || definition.input === 'select') {
                            return selectInput(id, label, value, this.fieldOptions(field, value))
                        }

                        return textInput(id, label, value)
                    })
                    .join('')

                return `
                    <div class="text-left">
                        ${fieldsHtml}
                        <label class="d-block text-left mb-1" for="waterline-filter-labels">${this.escapeHtml(labelsDefinition.label || 'Labels')}</label>
                        <textarea id="waterline-filter-labels" class="swal2-textarea" rows="4" placeholder="${labelPlaceholder}">${this.escapeHtml(this.labelsText(filters))}</textarea>
                        ${labelsDefinition.help
                            ? `<small class="d-block text-left text-muted mt-2">${this.escapeHtml(labelsDefinition.help)}</small>`
                            : ''}
                    </div>
                `
            },

            parseLabelText(value) {
                const labelsDefinition = this.visibilityLabelsDefinition()
                const separatorToken = labelsDefinition.key_value_separator || '='
                const separator = String(separatorToken)
                const labels = {}
                const lines = String(value || '')
                    .split('\n')
                    .map((line) => line.trim())
                    .filter((line) => line.length > 0)

                for (const line of lines) {
                    const separatorIndex = line.indexOf(separator)

                    if (separatorIndex === -1) {
                        throw new Error(`Use key${separator}value for label filters.`)
                    }

                    const key = line.slice(0, separatorIndex).trim()
                    const labelValue = line.slice(separatorIndex + separator.length).trim()

                    if (!this.labelKeyRegExp().test(key)) {
                        throw new Error(`Label keys must match ${this.labelKeyPattern()}.`)
                    }

                    if (!labelValue) {
                        throw new Error('Label values cannot be empty.')
                    }

                    labels[key] = labelValue
                }

                return labels
            },

            filteredQueryWithoutVisibilityFields() {
                const query = {...this.$route.query}

                this.visibilityFieldNames().forEach((field) => {
                    delete query[field]
                })

                delete query.label
                delete query.labels

                Object.keys(query).forEach((key) => {
                    if (this.labelQueryParameterPattern().test(key)) {
                        delete query[key]
                    }
                })

                return query
            },

            pushVisibilityFilters(filters, options = {}) {
                const query = this.filteredQueryWithoutVisibilityFields()

                if (options.clearView) {
                    delete query.view
                }

                Object.entries(filters).forEach(([field, value]) => {
                    if (field === 'labels') {
                        Object.entries(value || {}).forEach(([labelKey, labelValue]) => {
                            query[`labels[${labelKey}]`] = labelValue
                        })

                        return
                    }

                    if (value === undefined || value === null || value === '') {
                        return
                    }

                    query[field] = typeof value === 'boolean'
                        ? (value ? 'true' : 'false')
                        : value
                })

                this.$router.push({
                    name: this.$route.name,
                    params: this.$route.params,
                    query,
                })
            },

            async editFilters() {
                const current = this.currentFilterPayload()
                const result = await Swal.fire({
                    title: 'Edit Filters',
                    html: this.filterEditorHtml(current),
                    showCancelButton: true,
                    confirmButtonText: 'Apply Filters',
                    background: '#1c1c1c',
                    preConfirm: () => {
                        try {
                            const filters = {}
                            this.visibilityFieldEntries().forEach(([field]) => {
                                const element = document.getElementById(this.fieldInputId(field))

                                if (!element) {
                                    return
                                }

                                const value = this.normalizeFilterValue(field, element.value)

                                if (value !== undefined) {
                                    filters[field] = value
                                }
                            })

                            const labels = this.parseLabelText(document.getElementById('waterline-filter-labels').value)

                            if (Object.keys(labels).length > 0) {
                                filters.labels = labels
                            }

                            return filters
                        } catch (error) {
                            Swal.showValidationMessage(error.message)
                        }
                    },
                })

                if (result.isConfirmed) {
                    this.pushVisibilityFilters(result.value || {}, {clearView: false})
                }
            },

            clearFilters() {
                this.pushVisibilityFilters({}, {clearView: true})
            },

            async manageCurrentView() {
                if (!this.selectedCustomView) {
                    return
                }

                const view = this.selectedCustomView
                const result = await Swal.fire({
                    title: 'Manage View',
                    html: `
                        <label class="d-block text-left mb-1" for="waterline-view-name">Name</label>
                        <input id="waterline-view-name" class="swal2-input" value="${this.escapeHtml(view.name)}">
                        <label class="d-flex align-items-center justify-content-start mt-2">
                            <input id="waterline-view-shared" type="checkbox" class="mr-2" ${view.shared ? 'checked' : ''}>
                            <span>Shared within this Waterline scope</span>
                        </label>
                        <small class="d-block text-left text-muted mt-3">Update uses the current applied filters.</small>
                    `,
                    showCancelButton: true,
                    showDenyButton: true,
                    confirmButtonText: 'Update View',
                    denyButtonText: 'Delete View',
                    background: '#1c1c1c',
                    preConfirm: () => {
                        const name = document.getElementById('waterline-view-name').value.trim()

                        if (!name) {
                            Swal.showValidationMessage('Enter a view name.')
                            return
                        }

                        return {
                            name,
                            shared: document.getElementById('waterline-view-shared').checked,
                        }
                    },
                })

                if (result.isDenied) {
                    const confirmDelete = await Swal.fire({
                        title: 'Delete view?',
                        text: `Waterline will remove ${view.name}.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Delete View',
                        background: '#1c1c1c',
                    })

                    if (!confirmDelete.isConfirmed) {
                        return
                    }

                    try {
                        await this.$http.delete(Waterline.basePath + '/api/saved-views/' + view.id)
                        await this.loadSavedViews()
                        this.selectedSavedView = null
                        this.selectSavedView()
                    } catch (error) {
                        const message = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : 'Waterline could not delete this view.'

                        Swal.fire({
                            title: 'View not deleted',
                            text: message,
                            icon: 'error',
                            confirmButtonText: 'Okay',
                            background: '#1c1c1c',
                        })
                    }

                    return
                }

                if (!result.isConfirmed) {
                    return
                }

                try {
                    await this.$http.put(Waterline.basePath + '/api/saved-views/' + view.id, {
                        name: result.value.name,
                        bucket: this.$route.params.type,
                        filters: this.effectiveFilterPayload(),
                        shared: result.value.shared,
                    })

                    await this.loadSavedViews()
                    await this.loadFlows(this.page)
                } catch (error) {
                    const message = error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : 'Waterline could not update this view.'

                    Swal.fire({
                        title: 'View not updated',
                        text: message,
                        icon: 'error',
                        confirmButtonText: 'Okay',
                        background: '#1c1c1c',
                    })
                }
            },

            async saveCurrentView() {
                const result = await Swal.fire({
                    title: 'Save view',
                    input: 'text',
                    inputLabel: 'Name',
                    inputPlaceholder: this.flowCollectionLabel() + ' view',
                    showCancelButton: true,
                    confirmButtonText: 'Save view',
                    background: '#1c1c1c',
                    inputValidator: (value) => {
                        if (!value || !value.trim()) {
                            return 'Enter a view name.';
                        }

                        return null;
                    },
                });

                if (!result.isConfirmed) {
                    return;
                }

                try {
                    const response = await this.$http.post(Waterline.basePath + '/api/saved-views', {
                        name: result.value.trim(),
                        bucket: this.$route.params.type,
                        filters: this.effectiveFilterPayload(),
                        shared: true,
                    });

                    await this.loadSavedViews();

                    this.selectedSavedView = response.data.id;
                    this.selectSavedView();
                } catch (error) {
                    const message = error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : 'Waterline could not save this view.';

                    Swal.fire({
                        title: 'View not saved',
                        text: message,
                        icon: 'error',
                        confirmButtonText: 'Okay',
                        background: '#1c1c1c',
                    });
                }
            },

            loadNewEntries() {
                this.flows = [];

                this.loadFlows(1, false);

                this.hasNewEntries = false;
            },


            /**
             * Refresh the flows every period of time.
             */
            refreshFlowsPeriodically() {
                this.interval = setInterval(() => {
                    if (this.page != 1) {
                        return;
                    }

                    if (this.$root.autoLoadsNewEntries) {
                        this.loadFlows(1, true);
                    }
                }, 3000);
            },


            /**
             * Load the flows for the previous page.
             */
            previous() {
                this.loadFlows(
                    --this.page
                );
                this.hasNewEntries = false;
            },


            /**
             * Load the flows for the next page.
             */
            next() {
                this.loadFlows(
                    ++this.page
                );
                this.hasNewEntries = false;
            },

            flowCursor(flow) {
                if (!flow) {
                    return null;
                }

                return flow.sort_key || flow.id || null
            },

            /**
             * Update the page title.
             */
            updatePageTitle() {
                document.title = 'Waterline - ' + this.flowCollectionLabel() + ' Flows';
            },

            flowCollectionLabel() {
                return {
                    running: 'Running',
                    completed: 'Completed',
                    failed: 'Failed',
                    cancelled: 'Cancelled',
                    terminated: 'Terminated',
                }[this.$route.params.type] || 'Workflow';
            },

            isTerminalCollection() {
                return ['completed', 'failed', 'cancelled', 'terminated'].includes(this.$route.params.type);
            },

            closedAtLabel() {
                return this.$route.params.type === 'completed'
                    ? 'Completed At'
                    : this.flowCollectionLabel() + ' At';
            }
        }
    }
</script>

<template>
    <div>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>{{ flowCollectionLabel() }} Flows</h5>

                <div class="d-flex align-items-center flex-wrap justify-content-end">
                    <select v-if="savedViews.length"
                            v-model="selectedSavedView"
                            @change="selectSavedView"
                            class="custom-select custom-select-sm mr-2 mb-2"
                            style="width: 14rem;">
                        <option :value="null">System view</option>
                        <option v-for="view in savedViews" :key="view.id" :value="view.id">
                            {{ view.name }}
                        </option>
                    </select>

                    <button class="btn btn-outline-secondary btn-sm mr-2 mb-2"
                            @click="editFilters">
                        Filters
                    </button>

                    <button v-if="hasActiveFilters"
                            class="btn btn-outline-secondary btn-sm mr-2 mb-2"
                            @click="clearFilters">
                        Clear
                    </button>

                    <button v-if="savedViewsEnabled"
                            class="btn btn-outline-secondary btn-sm mr-2 mb-2"
                            @click="saveCurrentView">
                        Save View
                    </button>

                    <button v-if="selectedCustomView"
                            class="btn btn-outline-secondary btn-sm mb-2"
                            @click="manageCurrentView">
                        Manage View
                    </button>
                </div>
            </div>

            <div v-if="ready && (selectedSavedView || appliedFilterEntries.length)"
                 class="border-bottom px-3 py-2 card-bg-secondary">
                <div class="d-flex flex-wrap align-items-center">
                    <span v-if="visibilityFilters && visibilityFilters.saved_view"
                          class="badge badge-primary mr-2 mb-1">
                        View: {{ visibilityFilters.saved_view.name }}
                    </span>

                    <span v-for="entry in appliedFilterEntries"
                          :key="entry.key"
                          class="badge badge-secondary mr-2 mb-1">
                        {{ entry.label }}: {{ entry.value }}
                    </span>
                </div>
            </div>

            <div v-if="!ready"
                 class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin mr-2 fill-text-color">
                    <path
                        d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>

                <span>Loading...</span>
            </div>


            <div v-if="ready && flows.length == 0"
                 class="d-flex flex-column align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <span>There aren't any flows.</span>
            </div>

            <table v-if="ready && flows.length > 0" class="table table-hover table-sm mb-0">
                <thead>
                <tr>
                    <th>Flow</th>
                    <th v-if="$route.params.type=='running'" class="text-right">Started At</th>
                    <th v-if="isTerminalCollection()">Started At</th>
                    <th v-if="isTerminalCollection()">{{ closedAtLabel() }}</th>
                    <th v-if="isTerminalCollection()" class="text-right">Duration</th>
                </tr>
                </thead>

                <tbody>
                    <tr v-if="hasNewEntries" key="newEntries" class="dontanimate">
                        <td colspan="100" class="text-center card-bg-secondary py-1">
                            <small><a href="#" v-on:click.prevent="loadNewEntries" v-if="!loadingNewEntries">Load New
                                Entries</a></small>

                            <small v-if="loadingNewEntries">Loading...</small>
                        </td>
                    </tr>

                    <tr v-for="flow in flows" :key="flow.id" :flow="flow" is="flow-row">
                    </tr>
                </tbody>
            </table>

            <div v-if="ready && flows.length" class="p-3 d-flex justify-content-between border-top">
                <button @click="previous" class="btn btn-secondary btn-md" :disabled="page==1">Previous</button>
                <button @click="next" class="btn btn-secondary btn-md" :disabled="page>=totalPages">Next</button>
            </div>
        </div>

    </div>
</template>
