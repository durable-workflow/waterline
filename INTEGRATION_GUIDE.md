# Waterline Phase 1 Integration Guide

## Components Created

All components are in `/resources/js/components/`:

1. **PayloadInspector.vue** - Codec-aware payload renderer
2. **TimelineEventRenderer.vue** - Smart history event renderer  
3. **WorkerHealth.vue** - Worker fleet health dashboard
4. **ScheduleView.vue** - Schedule management UI
5. **SearchAttributeRenderer.vue** - Search attribute display

## Integration into flow.vue

### 1. Import Components

Add to the script section (around line 1379):

```javascript
import TimelineEventRenderer from '../components/TimelineEventRenderer.vue';
import SearchAttributeRenderer from '../components/SearchAttributeRenderer.vue';

export default {
    components: {
        TimelineEventRenderer,
        SearchAttributeRenderer
    },
    // ... rest of component
}
```

### 2. Replace Timeline Table (around line 538-580)

Replace the existing `<table>` in the History card with:

```vue
<div class="timeline-events">
    <TimelineEventRenderer
        v-for="event in flow.timeline"
        :key="event.id"
        :event="event"
        @drill-activity="navigateToActivity"
        @drill-child="navigateToChild"
    />
</div>
```

### 3. Add Navigation Methods

Add to methods section:

```javascript
navigateToActivity(activityExecutionId) {
    // Navigate to activity detail or show activity modal
    // Activity data is in flow.activities array
    const activity = (this.flow.activities || []).find(a => a.id === activityExecutionId);
    if (activity) {
        // Could open modal, navigate, or scroll to activities section
        console.log('Navigate to activity:', activity);
    }
},

navigateToChild(childRunId) {
    // Navigate to child workflow run
    this.$router.push({
        name: 'flow',
        params: { id: childRunId }
    });
}
```

### 4. Update Search Attributes Display (around line 196)

Replace the existing search attributes `<pre>` with:

```vue
<div class="row mb-2" v-if="hasObjectEntries(flow.search_attributes)">
    <div class="col-md-2"><strong>Search Attributes</strong></div>
    <div class="col">
        <SearchAttributeRenderer :attributes="flow.search_attributes" />
    </div>
</div>
```

## Adding Standalone Views

### Worker Health Page

Add route in `resources/js/routes.js`:

```javascript
{
    path: '/workers',
    name: 'workers',
    component: () => import('./components/WorkerHealth.vue')
}
```

### Schedule Management Page

Add route in `resources/js/routes.js`:

```javascript
{
    path: '/schedules',
    name: 'schedules',
    component: () => import('./components/ScheduleView.vue')
}
```

## Event Drill-Down

The TimelineEventRenderer emits two events:

- `@drill-activity` - when user clicks activity execution ID
- `@drill-child` - when user clicks child workflow run ID

Parent components should handle these to provide navigation.

## Styling

All components use existing Bootstrap 4 classes and maintain waterline's
dark theme. No additional CSS required beyond what's scoped in each component.

## Testing

1. Build assets: `npm run dev` or `npm run prod`
2. Navigate to a workflow run detail page
3. Verify:
   - Timeline renders with color-coded events
   - Payloads collapse/expand correctly
   - Search attributes display with type colors
   - Click-through on activity/child IDs (if navigation wired)

## API Contracts

Components expect data in the format returned by:
- `RunDetailView::forRun()` (workflow package)
- `V2HealthController::show()` (waterline)
- `V2SchedulesController::index()` (waterline)

No adapters needed - components consume the API shape directly.
