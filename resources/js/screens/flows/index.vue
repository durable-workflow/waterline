<script type="text/ecmascript-6">
    import FlowRow from './flow-row';
    import Swal from 'sweetalert2';

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
                operatorPreferences: {},
                effectiveOperatorPreferences: {},
                savingOperatorPreferences: false,
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

                Object.entries(applied.search_attributes || {}).forEach(([key, value]) => {
                    entries.push({
                        key: 'search_attribute:' + key,
                        label: 'Search Attribute',
                        value: key + '=' + value,
                    })
                })

                return entries
            },

            hasActiveFilters() {
                return this.appliedFilterEntries.length > 0 || !!this.selectedSavedView
            },

            workflowListColumns() {
                const columns = this.effectiveOperatorPreferences.columns

                return this.normalizeWorkflowListColumns(Array.isArray(columns) ? columns : null)
            },

            workflowListTableClass() {
                const classes = ['table', 'table-hover', 'mb-0']

                if (this.workflowListDensity() === 'dense') {
                    classes.push('table-sm')
                }

                return classes.join(' ')
            },

            activeFilterCount() {
                return this.appliedFilterEntries.length
            },

            flowCollectionDescription() {
                return {
                    running: 'Track live execution pressure, fresh arrivals, and claimability without leaving the operator queue.',
                    completed: 'Review recent completions, confirm expected outcomes, and jump straight into the runs that matter.',
                    failed: 'Surface failed runs, repair blockers, and compatibility warnings before they become recurring incidents.',
                    cancelled: 'Audit cancelled work, confirm intent, and reopen the flows that still need operator follow-through.',
                    terminated: 'Inspect force-stopped runs and verify downstream recovery finished the way the fleet expects.',
                }[this.$route.params.type] || 'Review workflow executions and open the runs that need attention.'
            },

            flowPageSummary() {
                return this.ready
                    ? `Page ${this.page} of ${Math.max(this.totalPages, 1)}`
                    : 'Waiting for results'
            },

            hasFilterContext() {
                return this.ready && (
                    !!this.selectedSavedView
                    || this.appliedFilterEntries.length > 0
                    || !!this.selectedSavedViewWarning()
                )
            },

            selectedViewDisplay() {
                if (this.visibilityFilters && this.visibilityFilters.saved_view && this.visibilityFilters.saved_view.name) {
                    return this.visibilityFilters.saved_view.name
                }

                if (!this.selectedSavedView) {
                    return 'Default'
                }

                const selected = this.savedViews.find((view) => view.id === this.selectedSavedView)

                return selected && selected.name
                    ? selected.name
                    : 'Saved view'
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
                await this.loadOperatorPreferences();

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
                        this.savedViews = views.filter((view) => !this.isDefaultSystemView(view));
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

            loadOperatorPreferences() {
                return this.$http.get(Waterline.basePath + '/api/preferences/workflow-list?' + this.operatorPreferenceQueryString())
                    .then(response => {
                        this.applyOperatorPreferencePayload(response.data || {})
                        this.applyPreferredSavedView()
                    })
                    .catch(() => {
                        this.operatorPreferences = {}
                        this.effectiveOperatorPreferences = {
                            sort_direction: 'desc',
                            row_density: 'dense',
                            columns: this.defaultWorkflowListColumns(),
                        }
                    })
            },

            applyOperatorPreferencePayload(payload) {
                this.operatorPreferences = payload.preferences || {}
                this.effectiveOperatorPreferences = {
                    sort_direction: 'desc',
                    row_density: 'dense',
                    columns: this.defaultWorkflowListColumns(),
                    ...(payload.effective_preferences || {}),
                }
                this.effectiveOperatorPreferences.columns = this.normalizeWorkflowListColumns(
                    this.effectiveOperatorPreferences.columns
                )
            },

            applyPreferredSavedView() {
                if (this.routeSavedViewOverride() !== null) {
                    return
                }

                const preferenceView = this.effectiveOperatorPreferences.saved_view_id || null

                if (!preferenceView) {
                    this.selectedSavedView = null
                    return
                }

                this.selectedSavedView = this.savedViews.find((view) => view.id === preferenceView)
                    ? preferenceView
                    : null
            },

            routeSavedViewOverride() {
                return typeof this.$route.query.view === 'string' && this.$route.query.view.length > 0
                    ? this.$route.query.view
                    : null
            },

            operatorPreferenceQueryString() {
                const params = new URLSearchParams()
                const query = this.$route.query || {}

                if (query.sort !== undefined) {
                    params.set('sort', query.sort)
                }

                if (query.sort_direction !== undefined) {
                    params.set('sort_direction', query.sort_direction)
                }

                if (query.density !== undefined) {
                    params.set('density', query.density)
                }

                if (query.row_density !== undefined) {
                    params.set('row_density', query.row_density)
                }

                if (query.view !== undefined) {
                    params.set('view', query.view)
                }

                if (query.saved_view !== undefined) {
                    params.set('saved_view', query.saved_view)
                }

                if (query.saved_view_id !== undefined) {
                    params.set('saved_view_id', query.saved_view_id)
                }

                if (query.columns !== undefined) {
                    params.set('columns', Array.isArray(query.columns) ? query.columns.join(',') : query.columns)
                }

                return params.toString()
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

                if (!params.has('sort') && !params.has('sort_direction')) {
                    params.set('sort_direction', this.workflowListSortDirection())
                }

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

                this.persistWorkflowListPreferences({
                    saved_view_id: selectedView,
                });
            },

            workflowListSortDirection() {
                return this.effectiveOperatorPreferences.sort_direction === 'asc'
                    ? 'asc'
                    : 'desc'
            },

            workflowListDensity() {
                return this.effectiveOperatorPreferences.row_density === 'comfortable'
                    ? 'comfortable'
                    : 'dense'
            },

            defaultWorkflowListColumns() {
                return ['flow', 'started_at', 'closed_at', 'duration', 'actions']
            },

            workflowListColumnOptions() {
                const options = [
                    {key: 'flow', label: 'Flow'},
                    {key: 'started_at', label: 'Started At'},
                ]

                if (this.isTerminalCollection()) {
                    options.push({key: 'closed_at', label: this.closedAtLabel()})
                    options.push({key: 'duration', label: 'Duration'})
                }

                options.push({key: 'actions', label: 'Actions'})

                return options
            },

            normalizeWorkflowListColumns(columns) {
                const allowed = this.workflowListColumnOptions().map((column) => column.key)
                const requested = Array.isArray(columns) ? columns : this.defaultWorkflowListColumns()
                const normalized = requested.filter((column) => allowed.includes(column))

                if (!normalized.includes('flow')) {
                    normalized.unshift('flow')
                }

                if (!normalized.includes('actions')) {
                    normalized.push('actions')
                }

                return normalized.length > 0
                    ? normalized
                    : this.defaultWorkflowListColumns().filter((column) => allowed.includes(column))
            },

            columnEnabled(column) {
                return this.workflowListColumns.includes(column)
            },

            swalBackground() {
                return this.$root && this.$root.theme === 'light' ? '#ffffff' : '#1c1c1c'
            },

            async persistWorkflowListPreferences(preferences, options = {}) {
                const payload = {
                    ...this.operatorPreferences,
                    ...preferences,
                }

                if (payload.columns) {
                    payload.columns = this.normalizeWorkflowListColumns(payload.columns)
                }

                this.savingOperatorPreferences = true

                try {
                    const response = await this.$http.put(Waterline.basePath + '/api/preferences/workflow-list', {
                        preferences: payload,
                    })
                    this.applyOperatorPreferencePayload(response.data || {})

                    if (options.reload === true) {
                        await this.loadFlows(1)
                    }
                } finally {
                    this.savingOperatorPreferences = false
                }
            },

            async editViewOptions() {
                const columns = this.workflowListColumns
                const columnHtml = this.workflowListColumnOptions().map((column) => `
                    <label class="d-flex align-items-center justify-content-start mb-2" for="waterline-column-${this.escapeHtml(column.key)}">
                        <input id="waterline-column-${this.escapeHtml(column.key)}"
                               type="checkbox"
                               class="mr-2 waterline-column-option"
                               value="${this.escapeHtml(column.key)}"
                               ${columns.includes(column.key) ? 'checked' : ''}
                               ${['flow', 'actions'].includes(column.key) ? 'disabled' : ''}>
                        <span>${this.escapeHtml(column.label)}</span>
                    </label>
                `).join('')

                const result = await Swal.fire({
                    title: 'View Options',
                    html: `
                        <div class="text-left">
                            <label class="d-block mb-1">Density</label>
                            <select id="waterline-list-density" class="swal2-input">
                                <option value="dense" ${this.workflowListDensity() === 'dense' ? 'selected' : ''}>Dense</option>
                                <option value="comfortable" ${this.workflowListDensity() === 'comfortable' ? 'selected' : ''}>Comfortable</option>
                            </select>
                            <label class="d-block mb-1 mt-3">Sort</label>
                            <select id="waterline-list-sort-direction" class="swal2-input">
                                <option value="desc" ${this.workflowListSortDirection() === 'desc' ? 'selected' : ''}>Newest first</option>
                                <option value="asc" ${this.workflowListSortDirection() === 'asc' ? 'selected' : ''}>Oldest first</option>
                            </select>
                            <div class="mt-3">
                                <label class="d-block mb-2">Columns</label>
                                ${columnHtml}
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Save Options',
                    background: this.swalBackground(),
                    preConfirm: () => {
                        const selectedColumns = Array.from(document.querySelectorAll('.waterline-column-option'))
                            .filter((input) => input.checked || input.value === 'flow')
                            .map((input) => input.value)

                        return {
                            row_density: document.getElementById('waterline-list-density').value,
                            sort_direction: document.getElementById('waterline-list-sort-direction').value,
                            columns: selectedColumns,
                        }
                    },
                })

                if (!result.isConfirmed) {
                    return
                }

                await this.persistWorkflowListPreferences(result.value, {reload: true})
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

                const searchAttributes = {};

                Object.entries(this.$route.query || {}).forEach(([key, value]) => {
                    const match = key.match(this.searchAttributeQueryParameterPattern());

                    if (match && typeof value === 'string' && value.length > 0) {
                        searchAttributes[match[1]] = value;
                    }
                });

                ['search_attribute', 'search_attributes'].forEach((key) => {
                    const value = this.$route.query[key];

                    if (!value || typeof value !== 'object' || Array.isArray(value)) {
                        return;
                    }

                    Object.entries(value).forEach(([attrKey, attrValue]) => {
                        if (this.searchAttributeKeyRegExp().test(attrKey) && typeof attrValue === 'string' && attrValue.length > 0) {
                            searchAttributes[attrKey] = attrValue;
                        }
                    });
                });

                if (Object.keys(searchAttributes).length > 0) {
                    filters.search_attributes = searchAttributes;
                }

                return filters;
            },

            mergeFilterPayloads(...payloads) {
                const merged = {}

                payloads.forEach((payload) => {
                    if (!payload || typeof payload !== 'object') {
                        return
                    }

                    Object.entries(payload).forEach(([field, value]) => {
                        if (field === 'labels') {
                            merged.labels = {
                                ...(merged.labels || {}),
                                ...(value || {}),
                            }

                            return
                        }

                        if (value === undefined || value === null || value === '') {
                            return
                        }

                        merged[field] = value
                    })
                })

                if (merged.labels && Object.keys(merged.labels).length === 0) {
                    delete merged.labels
                }

                return merged
            },

            visibilityFilterContract() {
                return this.filterDefinition
                    || (this.visibilityFilters && this.visibilityFilters.definition)
                    || null
            },

            hasVisibilityFilterContract() {
                return this.visibilityFilterContract() !== null
            },

            visibilityFieldEntries() {
                const contract = this.visibilityFilterContract()

                return Object.entries((contract && contract.fields) || {})
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
                const contract = this.visibilityFilterContract()

                return contract && contract.fields
                    ? (contract.fields[field] || null)
                    : null
            },

            visibilityLabelsDefinition() {
                const contract = this.visibilityFilterContract()

                return contract && contract.labels
                    ? contract.labels
                    : null
            },

            metadataContractEntries(sectionKey) {
                const contract = this.visibilityFilterContract()
                const section = contract && contract[sectionKey] && typeof contract[sectionKey] === 'object'
                    ? contract[sectionKey]
                    : {}

                return Object.entries(section).map(([key, definition]) => ({
                    key,
                    ...(definition && typeof definition === 'object' ? definition : {}),
                }))
            },

            labelKeyPattern() {
                const definition = this.visibilityLabelsDefinition()

                return definition && definition.key_pattern
                    ? definition.key_pattern
                    : '^$'
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

            searchAttributesDefinition() {
                const contract = this.visibilityFilterContract()

                return contract && contract.search_attributes
                    ? contract.search_attributes
                    : null
            },

            searchAttributeKeyPattern() {
                const definition = this.searchAttributesDefinition()

                return definition && definition.key_pattern
                    ? definition.key_pattern
                    : '^$'
            },

            searchAttributeKeyRegExp() {
                return new RegExp(this.searchAttributeKeyPattern())
            },

            searchAttributeQueryParameterPattern() {
                const keyPattern = this.searchAttributeKeyPattern()
                    .replace(/^\^/, '')
                    .replace(/\$$/, '')

                return new RegExp(`^search_attributes?\\[(${keyPattern})\\]$`)
            },

            searchAttributesText(filters) {
                return Object.entries(filters.search_attributes || {})
                    .map(([key, value]) => key + '=' + value)
                    .join('\n')
            },

            parseSearchAttributeText(value) {
                const definition = this.searchAttributesDefinition()

                if (!definition) {
                    return {}
                }

                const separatorToken = definition.key_value_separator || '='
                const separator = String(separatorToken)
                const attributes = {}
                const lines = String(value || '')
                    .split('\n')
                    .map((line) => line.trim())
                    .filter((line) => line.length > 0)

                for (const line of lines) {
                    const separatorIndex = line.indexOf(separator)

                    if (separatorIndex === -1) {
                        throw new Error(`Use key${separator}value for search attribute filters.`)
                    }

                    const key = line.slice(0, separatorIndex).trim()
                    const attrValue = line.slice(separatorIndex + separator.length).trim()

                    if (!this.searchAttributeKeyRegExp().test(key)) {
                        throw new Error(`Search attribute keys must match ${this.searchAttributeKeyPattern()}.`)
                    }

                    if (!attrValue) {
                        throw new Error('Search attribute values cannot be empty.')
                    }

                    attributes[key] = attrValue
                }

                return attributes
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
                const requestOrApplied = this.visibilityFilters && this.visibilityFilters.applied
                    ? this.visibilityFilters.applied
                    : this.currentFilterPayload()
                const usesIncompatibleSavedView = this.selectedCustomView
                    && !this.savedViewVersionSupported(this.selectedCustomView)
                    && (!this.visibilityFilters || this.visibilityFilters.saved_view_applied === false)
                const applied = usesIncompatibleSavedView
                    ? this.mergeFilterPayloads(this.selectedCustomView.filters || {}, requestOrApplied)
                    : requestOrApplied

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

            filterMetadataNoticeHtml() {
                const sections = [
                    {
                        label: 'Indexed metadata',
                        entries: this.metadataContractEntries('indexed_metadata'),
                    },
                    {
                        label: 'Detail only',
                        entries: this.metadataContractEntries('detail_metadata'),
                    },
                ].filter((section) => section.entries.length > 0)

                if (sections.length === 0) {
                    return ''
                }

                return `
                    <div class="text-left card-bg-secondary rounded px-3 py-2 mb-3">
                        ${sections.map((section, index) => `
                            <div class="${index > 0 ? 'mt-2' : ''}">
                                <strong>${this.escapeHtml(section.label)}</strong>
                                <ul class="mb-0 pl-3 small">
                                    ${section.entries.map((entry) => `
                                        <li><span class="font-weight-bold">${this.escapeHtml(entry.label || entry.key)}</span>${entry.description ? ': ' + this.escapeHtml(entry.description) : ''}</li>
                                    `).join('')}
                                </ul>
                            </div>
                        `).join('')}
                    </div>
                `
            },

            containsFieldPairs() {
                const pairs = {}

                this.visibilityFieldEntries().forEach(([field, definition]) => {
                    if (definition.operator === 'contains' && definition.contains_field) {
                        pairs[definition.contains_field] = field
                    }
                })

                return pairs
            },

            isContainsOperatorField(definition) {
                return definition
                    && definition.operator === 'contains'
                    && !!definition.contains_field
            },

            visibleFilterEditorEntries() {
                return this.visibilityFieldEntries()
                    .filter(([, definition]) => !this.isContainsOperatorField(definition))
            },

            operatorFieldLabel(definition, fallback) {
                const label = definition && definition.label
                    ? definition.label
                    : fallback

                return this.escapeHtml(label)
            },

            filterEditorHtml(filters) {
                const labelsDefinition = this.visibilityLabelsDefinition()

                if (!labelsDefinition) {
                    return '<div class="text-left">Visibility filters are unavailable.</div>'
                }

                const textInput = (id, label, value, help = '', labelClass = 'd-block text-left mb-1') => `
                    <label class="${labelClass}" for="${id}">${label}</label>
                    <input id="${id}" class="swal2-input mt-1" value="${this.escapeHtml(value)}">
                    ${help}
                `
                const selectInput = (id, label, value, options, help = '', labelClass = 'd-block text-left mb-1') => `
                    <label class="${labelClass}" for="${id}">${label}</label>
                    <select id="${id}" class="swal2-input mt-1">
                        <option value="" ${value === '' ? 'selected' : ''}>Any</option>
                        ${options.map((option) => `
                            <option value="${this.escapeHtml(this.optionValueString(option.value))}" ${value === this.optionValueString(option.value) ? 'selected' : ''}>${this.escapeHtml(option.label)}</option>
                        `).join('')}
                    </select>
                    ${help}
                `
                const fieldInput = (field, definition, labelClass = 'd-block text-left mb-1', labelOverride = null) => {
                    const id = this.fieldInputId(field)
                    const label = labelOverride === null
                        ? this.operatorFieldLabel(definition, field)
                        : this.escapeHtml(labelOverride)
                    const value = this.filterValue(field, filters)
                    const help = definition.help
                        ? `<small class="d-block text-left text-muted mt-2">${this.escapeHtml(definition.help)}</small>`
                        : ''

                    if (definition.input === 'boolean_select' || definition.input === 'select') {
                        return selectInput(id, label, value, this.fieldOptions(field, value), help, labelClass)
                    }

                    return textInput(id, label, value, help, labelClass)
                }
                const labelPlaceholder = this.escapeHtml(labelsDefinition.placeholder || '')
                    .replace(/\n/g, '&#10;')
                const containsPairs = this.containsFieldPairs()
                const fieldsHtml = this.visibleFilterEditorEntries()
                    .map(([field, definition]) => {
                        const containsField = containsPairs[field]
                        const containsDefinition = containsField
                            ? this.visibilityFieldDefinition(containsField)
                            : null

                        if (!containsField || !containsDefinition) {
                            return fieldInput(field, definition)
                        }

                        return `
                            <div class="text-left mt-3 mb-2">
                                <div class="font-weight-bold mb-2">${this.escapeHtml(definition.label || field)}</div>
                                ${fieldInput(field, definition, 'd-block text-left mb-1 small text-uppercase text-muted', 'Exact match')}
                                ${fieldInput(containsField, containsDefinition, 'd-block text-left mb-1 mt-3 small text-uppercase text-muted', 'Contains')}
                            </div>
                        `
                    })
                    .join('')

                const searchAttrDefinition = this.searchAttributesDefinition()
                const searchAttrPlaceholder = searchAttrDefinition
                    ? this.escapeHtml(searchAttrDefinition.placeholder || '').replace(/\n/g, '&#10;')
                    : ''
                const searchAttrHtml = searchAttrDefinition
                    ? `
                        <label class="d-block text-left mb-1 mt-3" for="waterline-filter-search-attributes">${this.escapeHtml(searchAttrDefinition.label || 'Search Attributes')}</label>
                        <textarea id="waterline-filter-search-attributes" class="swal2-textarea" rows="4" placeholder="${searchAttrPlaceholder}">${this.escapeHtml(this.searchAttributesText(filters))}</textarea>
                        ${searchAttrDefinition.help
                            ? `<small class="d-block text-left text-muted mt-2">${this.escapeHtml(searchAttrDefinition.help)}</small>`
                            : ''}
                    `
                    : ''

                return `
                    <div class="text-left">
                        ${this.filterMetadataNoticeHtml()}
                        ${fieldsHtml}
                        <label class="d-block text-left mb-1" for="waterline-filter-labels">${this.escapeHtml(labelsDefinition.label || 'Labels')}</label>
                        <textarea id="waterline-filter-labels" class="swal2-textarea" rows="4" placeholder="${labelPlaceholder}">${this.escapeHtml(this.labelsText(filters))}</textarea>
                        ${labelsDefinition.help
                            ? `<small class="d-block text-left text-muted mt-2">${this.escapeHtml(labelsDefinition.help)}</small>`
                            : ''}
                        ${searchAttrHtml}
                    </div>
                `
            },

            parseLabelText(value) {
                const labelsDefinition = this.visibilityLabelsDefinition()

                if (!labelsDefinition) {
                    return {}
                }

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
                delete query.search_attribute
                delete query.search_attributes

                Object.keys(query).forEach((key) => {
                    if (this.labelQueryParameterPattern().test(key)) {
                        delete query[key]
                    }

                    if (this.searchAttributeQueryParameterPattern().test(key)) {
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

                    if (field === 'search_attributes') {
                        Object.entries(value || {}).forEach(([attrKey, attrValue]) => {
                            query[`search_attributes[${attrKey}]`] = attrValue
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
                const current = this.selectedCustomView && !this.savedViewVersionSupported(this.selectedCustomView)
                    ? this.mergeFilterPayloads(this.selectedCustomView.filters || {}, this.currentFilterPayload())
                    : this.currentFilterPayload()
                const result = await Swal.fire({
                    title: 'Edit Filters',
                    html: this.filterEditorHtml(current),
                    showCancelButton: true,
                    confirmButtonText: 'Apply Filters',
                    background: this.swalBackground(),
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

                            const searchAttrEl = document.getElementById('waterline-filter-search-attributes')

                            if (searchAttrEl) {
                                const searchAttributes = this.parseSearchAttributeText(searchAttrEl.value)

                                if (Object.keys(searchAttributes).length > 0) {
                                    filters.search_attributes = searchAttributes
                                }
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
                const updateNote = view.filter_version_supported === false
                    ? `This view uses filter version ${view.filter_version}. Updating rewrites it to the current contract with the stored view filters plus any query refinements.`
                    : 'Update uses the current applied filters.'
                const result = await Swal.fire({
                    title: 'Manage View',
                    html: `
                        <label class="d-block text-left mb-1" for="waterline-view-name">Name</label>
                        <input id="waterline-view-name" class="swal2-input" value="${this.escapeHtml(view.name)}">
                        <label class="d-flex align-items-center justify-content-start mt-2">
                            <input id="waterline-view-shared" type="checkbox" class="mr-2" ${view.shared ? 'checked' : ''}>
                            <span>Shared within this Waterline scope</span>
                        </label>
                        <small class="d-block text-left text-muted mt-3">${this.escapeHtml(updateNote)}</small>
                    `,
                    showCancelButton: true,
                    showDenyButton: true,
                    confirmButtonText: 'Update View',
                    denyButtonText: 'Delete View',
                    background: this.swalBackground(),
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
                        background: this.swalBackground(),
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
                            background: this.swalBackground(),
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
                        background: this.swalBackground(),
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
                    background: this.swalBackground(),
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
                        background: this.swalBackground(),
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

            isDefaultSystemView(view) {
                return !!view
                    && view.system === true
                    && view.id === `system:${this.$route.params.type}`
            },

            savedViewVersionSupported(view) {
                return !view || view.filter_version_supported !== false
            },

            savedViewOptionLabel(view) {
                if (view && view.system === true) {
                    return `System: ${view.name}`
                }

                if (this.savedViewVersionSupported(view)) {
                    return view.name
                }

                return `${view.name} (upgrade needed)`
            },

            selectedSavedViewWarning() {
                if (this.visibilityFilters && this.visibilityFilters.saved_view_warning) {
                    return this.visibilityFilters.saved_view_warning
                }

                if (this.selectedCustomView && this.selectedCustomView.filter_version_supported === false) {
                    return this.selectedCustomView.filter_version_message
                        || 'This saved view uses an unsupported visibility filter contract.'
                }

                return null
            },

            canManageSelectedCustomView() {
                return !!this.selectedCustomView
                    && this.selectedCustomView.mutable_by_current_operator !== false
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
    <div class="flow-index">
        <section class="flow-index__hero">
            <div>
                <p class="flow-index__eyebrow">Workflow Operations</p>
                <h1 class="flow-index__title">{{ flowCollectionLabel() }} Flows</h1>
                <p class="flow-index__subtitle">{{ flowCollectionDescription }}</p>
            </div>

            <div class="flow-index__summary-grid">
                <article class="flow-index__metric">
                    <span class="flow-index__metric-label">Visible On Page</span>
                    <strong class="flow-index__metric-value">{{ ready ? flows.length : '...' }}</strong>
                    <p class="flow-index__metric-copy">
                        {{ hasNewEntries ? 'Fresh runs are waiting at the top of the queue.' : 'Current registry slice loaded for review.' }}
                    </p>
                </article>

                <article class="flow-index__metric">
                    <span class="flow-index__metric-label">Queue Window</span>
                    <strong class="flow-index__metric-value">{{ flowPageSummary }}</strong>
                    <p class="flow-index__metric-copy">
                        {{ workflowListSortDirection() === 'desc' ? 'Newest runs first for fast triage.' : 'Oldest runs first for chronological review.' }}
                    </p>
                </article>

                <article class="flow-index__metric">
                    <span class="flow-index__metric-label">Applied Filters</span>
                    <strong class="flow-index__metric-value">{{ activeFilterCount }}</strong>
                    <p class="flow-index__metric-copy">
                        {{ hasActiveFilters ? 'This collection is narrowed by a saved view or manual filters.' : 'The default collection is currently in view.' }}
                    </p>
                </article>

                <article class="flow-index__metric">
                    <span class="flow-index__metric-label">Presentation</span>
                    <strong class="flow-index__metric-value">{{ selectedViewDisplay }}</strong>
                    <p class="flow-index__metric-copy">
                        {{ workflowListDensity() === 'dense' ? 'Dense rows keep more history visible at once.' : 'Comfortable spacing favors deeper inspection.' }}
                    </p>
                </article>
            </div>
        </section>

        <section class="flow-index__panel card">
            <div class="card-body flow-index__controls">
                <div class="flow-index__controls-copy">
                    <p class="flow-index__section-kicker">Views And Filters</p>
                    <h2 class="flow-index__section-title">Shape the operator queue</h2>
                    <p class="flow-index__section-copy">
                        Saved views, visibility filters, and display options stay wired to Waterline's existing preference contract.
                    </p>
                </div>

                <div class="flow-index__toolbar">
                    <select v-if="savedViews.length"
                            v-model="selectedSavedView"
                            @change="selectSavedView"
                            class="custom-select custom-select-sm flow-index__view-select">
                        <option :value="null">Default View</option>
                        <option v-for="view in savedViews" :key="view.id" :value="view.id">
                            {{ savedViewOptionLabel(view) }}
                        </option>
                    </select>

                    <div class="flow-index__toolbar-actions">
                        <button v-if="hasVisibilityFilterContract()"
                                class="btn btn-outline-secondary btn-sm"
                                @click="editFilters">
                            Filters
                        </button>

                        <button v-if="hasVisibilityFilterContract() && hasActiveFilters"
                                class="btn btn-outline-secondary btn-sm"
                                @click="clearFilters">
                            Clear
                        </button>

                        <button v-if="savedViewsEnabled && hasVisibilityFilterContract()"
                                class="btn btn-outline-secondary btn-sm"
                                @click="saveCurrentView">
                            Save View
                        </button>

                        <button v-if="canManageSelectedCustomView()"
                                class="btn btn-outline-secondary btn-sm"
                                @click="manageCurrentView">
                            Manage View
                        </button>

                        <button class="btn btn-outline-secondary btn-sm"
                                :disabled="savingOperatorPreferences"
                                @click="editViewOptions">
                            View Options
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="hasFilterContext" class="flow-index__chips">
                <span v-if="visibilityFilters && visibilityFilters.saved_view" class="badge badge-primary">
                    View: {{ visibilityFilters.saved_view.name }}
                </span>

                <span v-if="selectedSavedViewWarning()" class="badge badge-dark">
                    {{ selectedSavedViewWarning() }}
                </span>

                <span v-for="entry in appliedFilterEntries" :key="entry.key" class="badge badge-secondary">
                    {{ entry.label }}: {{ entry.value }}
                </span>
            </div>
        </section>

        <section class="flow-index__panel card">
            <div class="card-body flow-index__registry-head">
                <div>
                    <p class="flow-index__section-kicker">Registry</p>
                    <h2 class="flow-index__section-title">{{ flowCollectionLabel() }} flow registry</h2>
                    <p class="flow-index__section-copy">
                        {{ ready ? `Showing ${flows.length} ${flows.length === 1 ? 'flow' : 'flows'} on the current page.` : 'Loading the current workflow collection.' }}
                    </p>
                </div>

                <div class="flow-index__registry-meta">
                    <span class="flow-index__pill">{{ flowPageSummary }}</span>
                    <span class="flow-index__pill is-muted">
                        {{ workflowListSortDirection() === 'desc' ? 'Newest first' : 'Oldest first' }}
                    </span>
                </div>
            </div>

            <div v-if="!ready" class="flow-index__state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin flow-index__state-icon fill-text-color">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>
                <p class="flow-index__state-copy">Loading the registry, current filters, and saved views.</p>
            </div>

            <div v-else-if="flows.length === 0" class="flow-index__state flow-index__state--empty">
                <strong>{{ hasActiveFilters ? 'No flows match the current view.' : 'No flows in this collection yet.' }}</strong>
                <p class="flow-index__state-copy">
                    {{ hasActiveFilters ? 'Clear filters or switch views to widen the queue.' : 'Waterline will populate this registry when runs enter this collection.' }}
                </p>
            </div>

            <div v-else class="flow-index__table-wrap">
                <table :class="[workflowListTableClass, 'flow-index__table']">
                    <thead>
                    <tr>
                        <th v-if="columnEnabled('flow')">Flow</th>
                        <th v-if="columnEnabled('started_at')"
                            :class="$route.params.type=='running' ? 'text-right' : ''">
                            Started At
                        </th>
                        <th v-if="isTerminalCollection() && columnEnabled('closed_at')">{{ closedAtLabel() }}</th>
                        <th v-if="isTerminalCollection() && columnEnabled('duration')" class="text-right">Duration</th>
                        <th v-if="columnEnabled('actions')" class="text-right">Actions</th>
                    </tr>
                    </thead>

                    <tbody>
                        <tr v-if="hasNewEntries" key="newEntries" class="dontanimate flow-index__new-entries">
                            <td colspan="100">
                                <div class="flow-index__new-entries-inner">
                                    <span>New runs are waiting.</span>
                                    <small v-if="!loadingNewEntries">
                                        <a href="#" v-on:click.prevent="loadNewEntries">Load new entries</a>
                                    </small>
                                    <small v-else>Loading...</small>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="flow in flows" :key="flow.id" :flow="flow" :columns="workflowListColumns" is="flow-row">
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="ready && flows.length" class="flow-index__pagination">
                <p class="flow-index__pagination-copy">
                    Showing {{ flows.length }} {{ flows.length === 1 ? 'flow' : 'flows' }} on {{ flowPageSummary.toLowerCase() }}.
                </p>

                <div class="flow-index__pagination-actions">
                    <button @click="previous" class="btn btn-secondary btn-md" :disabled="page==1">Previous</button>
                    <button @click="next" class="btn btn-secondary btn-md" :disabled="page>=totalPages">Next</button>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.flow-index {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.flow-index__hero {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.flow-index__eyebrow,
.flow-index__section-kicker {
    margin: 0 0 0.45rem;
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.flow-index__title {
    margin: 0;
    color: var(--wl-text);
    font-size: 2.1rem;
    font-weight: 600;
    letter-spacing: -0.04em;
}

.flow-index__subtitle,
.flow-index__section-copy,
.flow-index__metric-copy,
.flow-index__state-copy,
.flow-index__pagination-copy {
    margin: 0.5rem 0 0;
    color: var(--wl-text-muted);
}

.flow-index__summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}

.flow-index__metric {
    padding: 1.1rem 1.2rem;
    border: 1px solid color-mix(in srgb, var(--wl-text) 8%, transparent);
    border-radius: 18px;
    background: color-mix(in srgb, var(--wl-text) 4%, var(--wl-surface));
}

.flow-index__metric-label {
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.flow-index__metric-value {
    display: block;
    margin-top: 0.7rem;
    color: var(--wl-text);
    font-size: 1.9rem;
    font-weight: 600;
    letter-spacing: -0.04em;
}

.flow-index__panel {
    overflow: hidden;
}

.flow-index__controls,
.flow-index__registry-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1.25rem;
    flex-wrap: wrap;
}

.flow-index__controls-copy {
    max-width: 38rem;
}

.flow-index__section-title {
    margin: 0;
    color: var(--wl-text);
    font-size: 1.3rem;
    font-weight: 600;
    letter-spacing: -0.03em;
}

.flow-index__toolbar,
.flow-index__toolbar-actions,
.flow-index__registry-meta,
.flow-index__pagination-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.flow-index__toolbar {
    justify-content: flex-end;
}

.flow-index__toolbar-actions {
    justify-content: flex-end;
}

.flow-index__view-select {
    min-width: 16rem;
    max-width: 100%;
}

.flow-index__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    padding: 0 1.25rem 1.25rem;
}

.flow-index__chips .badge {
    padding: 0.45rem 0.7rem;
    border-radius: 999px;
}

.flow-index__pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--wl-accent) 12%, transparent);
    color: var(--wl-accent);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.flow-index__pill.is-muted {
    background: color-mix(in srgb, var(--wl-text) 5%, transparent);
    color: var(--wl-text-muted);
}

.flow-index__state {
    min-height: 18rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    text-align: center;
    border-top: 1px solid var(--wl-border);
}

.flow-index__state--empty strong {
    color: var(--wl-text);
}

.flow-index__state-icon {
    width: 2rem;
    height: 2rem;
}

.flow-index__table-wrap {
    overflow-x: auto;
    border-top: 1px solid var(--wl-border);
}

.flow-index__table {
    margin-bottom: 0 !important;
}

.flow-index__table thead th {
    border-top: none;
    border-bottom: 1px solid var(--wl-border);
    background: var(--wl-surface);
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.flow-index__table tbody tr:hover {
    background: color-mix(in srgb, var(--wl-text) 3%, transparent);
}

.flow-index__new-entries td {
    padding: 0;
    background: color-mix(in srgb, var(--wl-accent) 10%, transparent);
    border-bottom: 1px solid var(--wl-border);
}

.flow-index__new-entries-inner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.65rem;
    padding: 0.55rem 0.75rem;
    color: var(--wl-text-muted);
    font-size: 0.82rem;
}

.flow-index__new-entries a {
    color: var(--wl-accent);
}

.flow-index__pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem 1.25rem;
    border-top: 1px solid var(--wl-border);
}

.flow-index .icon {
    display: inline-block;
    vertical-align: middle;
}

.flow-index .icon.spin {
    animation: flow-index-spin 1s linear infinite;
}

@keyframes flow-index-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@media (max-width: 1200px) {
    .flow-index__summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .flow-index__summary-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .flow-index__toolbar,
    .flow-index__toolbar-actions,
    .flow-index__registry-meta,
    .flow-index__pagination,
    .flow-index__pagination-actions {
        width: 100%;
    }

    .flow-index__toolbar,
    .flow-index__toolbar-actions,
    .flow-index__registry-meta {
        justify-content: flex-start;
    }

    .flow-index__view-select {
        width: 100%;
    }

    .flow-index__pagination {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>
