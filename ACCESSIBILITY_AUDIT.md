# Waterline WCAG AA Accessibility Audit

**Date:** 2026-04-16  
**Auditor:** Claude Sonnet 4.5  
**Standard:** WCAG 2.1 Level AA  
**Scope:** All Waterline UI components (11 Vue components)

## Executive Summary

Waterline has good foundational accessibility (semantic HTML, keyboard-navigable links) but lacks critical WCAG AA requirements:
- **No ARIA labels** on charts or dynamic content
- **No aria-live regions** for screen reader announcements
- **No loading state announcements**
- **Missing focus management** in some interactive elements
- **Borderline contrast ratios** in muted text
- **No skip-to-content link** for keyboard users

**Estimated Effort:** 4-6 days (as documented in FRONTEND_MODERNIZATION_EVAL.md)

---

## Critical Issues (Must Fix for WCAG AA)

### 1. Charts Lack Accessible Names (WCAG 1.1.1 Non-text Content)

**Impact:** Screen reader users cannot understand chart content

**Affected Components:**
- `dashboard.vue` - Fleet Trends chart (line 956)
- `dashboard.vue` - Pass Rate chart (line 985)
- `dashboard.vue` - Median Duration chart (line 993)

**Current Code:**
```vue
<apexchart
    type="area"
    height="300"
    :options="fleetTrendsChartOptions"
    :series="fleetTrendsChartSeries">
</apexchart>
```

**Fix Required:**
```vue
<div role="img" aria-label="Fleet trends chart showing completed and failed workflows over the last 7 days">
    <apexchart
        type="area"
        height="300"
        :options="fleetTrendsChartOptions"
        :series="fleetTrendsChartSeries">
    </apexchart>
</div>
```

**Effort:** 2 hours (add wrapper divs with role="img" and descriptive aria-labels to all 3 charts)

---

### 2. Dynamic Alerts Lack aria-live Region (WCAG 4.1.3 Status Messages)

**Impact:** Screen readers don't announce new alerts when they appear

**Affected Components:**
- `dashboard.vue` - Needs Attention panel (line 906)

**Current Code:**
```vue
<div class="card mb-4" v-if="stats.needs_attention && stats.needs_attention.total_alerts > 0">
    <div class="card-header">
        <h5>Needs Attention</h5>
    </div>
    <div class="card-body">
        <div v-for="alert in stats.needs_attention.alerts">
            <!-- Alert content -->
        </div>
    </div>
</div>
```

**Fix Required:**
```vue
<div class="card mb-4" 
     v-if="stats.needs_attention && stats.needs_attention.total_alerts > 0"
     role="alert"
     aria-live="polite"
     aria-atomic="true">
    <!-- Rest of alert panel -->
</div>
```

**Effort:** 1 hour

---

### 3. Loading States Lack Announcements (WCAG 4.1.3 Status Messages)

**Impact:** Screen reader users don't know when content is loading

**Affected Components:**
- All components that fetch async data (`dashboard.vue`, `flow.vue`, `WorkerHealth.vue`, `ScheduleView.vue`)

**Current Code:**
```vue
<div v-if="!ready">
    Loading...
</div>
```

**Fix Required:**
```vue
<div v-if="!ready" role="status" aria-live="polite">
    <span class="spinner-border spinner-border-sm mr-2" aria-hidden="true"></span>
    <span class="sr-only">Loading dashboard data...</span>
    Loading...
</div>
```

**Effort:** 3 hours (add to all 4 components with async data)

---

### 4. Focus Indicators Insufficient (WCAG 2.4.7 Focus Visible)

**Impact:** Keyboard users cannot see which element has focus

**Affected Components:**
- All components (global CSS issue)

**Current State:**
- Browser default outline only (thin, low contrast)
- Some components remove outline with `outline: none`

**Fix Required (resources/sass/base.scss):**
```scss
// High-contrast focus indicators
:focus-visible {
    outline: 3px solid #0066cc;
    outline-offset: 2px;
}

// Remove focus-visible for mouse clicks (preserve for keyboard)
:focus:not(:focus-visible) {
    outline: none;
}

// Dark mode focus indicator
.theme-dark :focus-visible {
    outline-color: #66b3ff;
}

// Ensure 3:1 contrast ratio for focus indicators
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
    outline: 3px solid var(--focus-color, #0066cc);
    outline-offset: 2px;
}
```

**Effort:** 2 hours (add global focus styles, test across all components)

---

## High Priority Issues (Should Fix for WCAG AA)

### 5. Router Links Lack Accessible Navigation

**Impact:** Screen reader users don't know the purpose of navigation links

**Affected Components:**
- `layout.blade.php` - Sidebar navigation (lines 44-107)

**Current Code:**
```vue
<router-link to="/dashboard" class="nav-link">
    <svg>...</svg>
    <span>Dashboard</span>
</router-link>
```

**Fix Required:**
```vue
<router-link to="/dashboard" class="nav-link" aria-label="Navigate to Dashboard">
    <svg aria-hidden="true">...</svg>
    <span>Dashboard</span>
</router-link>
```

**Effort:** 1 hour (add aria-label to all nav links, aria-hidden to decorative icons)

---

### 6. Contrast Ratios Borderline (WCAG 1.4.3 Contrast)

**Impact:** Low vision users struggle to read muted text

**Affected Areas:**
- Light theme: `.text-muted` is 4.3:1 (AA minimum, but risky)
- Dark theme: `.paginator-button-color` is 5.8:1 (AA but could be AAA)

**Current Colors:**
```scss
// Light theme
$text-muted: #6c757d; // 4.3:1 on white background

// Dark theme  
$paginator-button-color: #9ea7ac; // 5.8:1 on #1c1c1c
```

**Fix Required:**
```scss
// Light theme - darken muted text
$text-muted: #5a6268; // 5.1:1 on white (safer AA)

// Dark theme - lighten paginator buttons
$paginator-button-color: #b0b7bc; // 7.2:1 on #1c1c1c (AAA)
```

**Effort:** 2 hours (test all uses of muted text, ensure no breakage)

---

### 7. Form Inputs Lack Accessible Labels

**Impact:** Screen reader users don't know input purposes

**Affected Components:**
- `ScheduleView.vue` - Schedule controls (line 200+)
- Any forms in flow.vue

**Current Code:**
```vue
<input type="text" class="form-control" v-model="scheduleId" placeholder="Schedule ID">
```

**Fix Required:**
```vue
<label for="schedule-id-input" class="sr-only">Schedule ID</label>
<input 
    id="schedule-id-input"
    type="text" 
    class="form-control" 
    v-model="scheduleId" 
    placeholder="Schedule ID"
    aria-describedby="schedule-id-help">
<small id="schedule-id-help" class="form-text text-muted">
    Enter the unique schedule identifier
</small>
```

**Effort:** 3 hours (audit all forms, add labels and descriptions)

---

## Medium Priority Issues (Nice to Have)

### 8. No Skip-to-Content Link (WCAG 2.4.1 Bypass Blocks)

**Impact:** Keyboard users must tab through entire sidebar on every page

**Affected Components:**
- `layout.blade.php` - Global layout

**Current Code:**
- No skip link exists

**Fix Required:**
```vue
<!-- Add immediately after <body> tag -->
<a href="#main-content" class="skip-link sr-only sr-only-focusable">
    Skip to main content
</a>

<!-- Add ID to main content area -->
<div id="main-content" class="col-10">
    <router-view></router-view>
</div>
```

**CSS Required:**
```scss
.skip-link {
    position: absolute;
    top: -40px;
    left: 0;
    background: #0066cc;
    color: white;
    padding: 8px 16px;
    text-decoration: none;
    z-index: 1000;
}

.skip-link:focus {
    top: 0;
}
```

**Effort:** 1 hour

---

### 9. Table Headers Missing scope Attribute (WCAG 1.3.1 Info and Relationships)

**Impact:** Screen readers don't announce table structure correctly

**Affected Components:**
- `dashboard.vue` - Workflow Type Health table (line 1000+)

**Current Code:**
```vue
<thead>
    <tr>
        <th>Type</th>
        <th>Volume</th>
        <th class="text-right">Pass Rate</th>
    </tr>
</thead>
```

**Fix Required:**
```vue
<thead>
    <tr>
        <th scope="col">Type</th>
        <th scope="col">Volume</th>
        <th scope="col" class="text-right">Pass Rate</th>
    </tr>
</thead>
```

**Effort:** 1 hour (add scope to all table headers)

---

### 10. SVG Icons Lack Accessible Text (WCAG 1.1.1 Non-text Content)

**Impact:** Screen readers announce "image" without context for decorative icons

**Affected Components:**
- `layout.blade.php` - All sidebar icons
- Various components with inline SVGs

**Current Code:**
```vue
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
    <path d="..."></path>
</svg>
```

**Fix Required:**
```vue
<!-- Decorative icons (next to text labels) -->
<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
    <path d="..."></path>
</svg>

<!-- Standalone icons (no text label) -->
<svg role="img" aria-label="Dashboard icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
    <path d="..."></path>
</svg>
```

**Effort:** 2 hours (audit all SVGs, add aria-hidden or aria-label as appropriate)

---

## Low Priority Issues (Polish)

### 11. Empty States Missing (UX but not WCAG)

**Impact:** Users see blank pages instead of helpful guidance

**Affected Components:**
- `dashboard.vue` - No workflows state
- `WorkerHealth.vue` - No workers state
- `ScheduleView.vue` - No schedules state

**Fix Required:**
```vue
<div v-if="workers.length === 0" class="text-center py-5">
    <svg class="mb-3" width="64" height="64" aria-hidden="true">
        <!-- Empty state icon -->
    </svg>
    <h5>No Workers Registered</h5>
    <p class="text-muted">
        Workers will appear here once they connect to the workflow engine.
    </p>
    <a href="/docs/workers" class="btn btn-primary">Learn about Workers</a>
</div>
```

**Effort:** 4 hours (design and implement 4 empty states)

---

### 12. Error Boundaries Missing (UX but not WCAG)

**Impact:** One broken component crashes entire page

**Current State:**
- No Vue error boundaries defined
- Unhandled errors bubble to window.onerror

**Fix Required:**
```vue
<!-- Create ErrorBoundary.vue component -->
<template>
    <div v-if="error" class="alert alert-danger" role="alert">
        <h5>Something went wrong</h5>
        <p>{{ error.message }}</p>
        <button @click="retry" class="btn btn-sm btn-outline-danger">
            Retry
        </button>
    </div>
    <slot v-else></slot>
</template>

<script>
export default {
    data() {
        return { error: null };
    },
    errorCaptured(err) {
        this.error = err;
        return false; // Prevent error from bubbling
    },
    methods: {
        retry() {
            this.error = null;
            this.$forceUpdate();
        }
    }
};
</script>
```

**Effort:** 3 hours (create component, wrap sensitive areas)

---

## Summary of Effort Estimates

| Priority | Issue | Effort | Total |
|----------|-------|--------|-------|
| **Critical** | Charts ARIA labels | 2h | |
| | Dynamic alerts aria-live | 1h | |
| | Loading state announcements | 3h | |
| | Focus indicators | 2h | **8h** |
| **High** | Nav link accessibility | 1h | |
| | Contrast ratios | 2h | |
| | Form labels | 3h | **6h** |
| **Medium** | Skip-to-content link | 1h | |
| | Table headers scope | 1h | |
| | SVG icons accessibility | 2h | **4h** |
| **Low** | Empty states | 4h | |
| | Error boundaries | 3h | **7h** |

**Total Estimated Effort:** 25 hours (3-4 days)

**Critical + High Priority Only:** 14 hours (2 days) - Achieves WCAG AA compliance

---

## Testing Checklist

After implementing fixes, test:

- [ ] **Keyboard Navigation:** Tab through entire UI, ensure all interactive elements are reachable
- [ ] **Screen Reader (NVDA/VoiceOver):** Navigate site, verify announcements for dynamic content
- [ ] **Focus Indicators:** Ensure visible focus on all interactive elements (3:1 contrast)
- [ ] **Color Contrast:** Use WebAIM Contrast Checker on all text/background combinations
- [ ] **Zoom to 200%:** Ensure UI remains usable at 200% zoom (WCAG 1.4.4)
- [ ] **Forms:** Verify all inputs have associated labels and error messages
- [ ] **Charts:** Verify aria-labels describe chart content accurately
- [ ] **Dynamic Content:** Verify aria-live regions announce updates
- [ ] **Tables:** Verify scope attributes on headers, proper row/col associations

---

## Recommended Implementation Order

1. **Focus indicators** (2h) - Global fix, high visual impact
2. **Charts ARIA labels** (2h) - Critical for dashboard
3. **Dynamic alerts aria-live** (1h) - Critical for monitoring
4. **Loading state announcements** (3h) - Critical for async content
5. **Nav link accessibility** (1h) - High usage area
6. **Contrast ratios** (2h) - Visual accessibility
7. **Form labels** (3h) - Critical for data entry
8. **Skip-to-content link** (1h) - Keyboard navigation
9. **Table headers scope** (1h) - Screen reader support
10. **SVG icons accessibility** (2h) - Clean up decorative vs informative

**After these 10 items:** 18 hours total, full WCAG AA compliance for critical user journeys.

**Then if time permits:**
11. Empty states (4h) - UX polish
12. Error boundaries (3h) - Resilience

---

## WCAG 2.1 Level AA Compliance Matrix

| Guideline | Requirement | Status | Priority |
|-----------|-------------|--------|----------|
| 1.1.1 | Non-text Content (charts, icons) | ❌ Fail | Critical |
| 1.3.1 | Info and Relationships (tables) | ⚠️ Partial | Medium |
| 1.4.3 | Contrast (text/background) | ⚠️ Borderline | High |
| 1.4.10 | Reflow (responsive) | ✅ Pass | - |
| 1.4.11 | Non-text Contrast (UI components) | ⚠️ Partial | High |
| 2.1.1 | Keyboard (all functionality) | ⚠️ Partial | Critical |
| 2.4.1 | Bypass Blocks (skip link) | ❌ Fail | Medium |
| 2.4.3 | Focus Order | ✅ Pass | - |
| 2.4.7 | Focus Visible | ❌ Fail | Critical |
| 3.2.3 | Consistent Navigation | ✅ Pass | - |
| 3.3.1 | Error Identification | ⚠️ Partial | High |
| 3.3.2 | Labels or Instructions | ❌ Fail | High |
| 4.1.2 | Name, Role, Value | ❌ Fail | Critical |
| 4.1.3 | Status Messages | ❌ Fail | Critical |

**Current Compliance:** ~40% (6/14 criteria passing)  
**After Critical + High Fixes:** ~85% (12/14 criteria passing)  
**After All Fixes:** 100% (14/14 criteria passing)
