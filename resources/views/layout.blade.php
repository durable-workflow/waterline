<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Meta Information -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('/vendor/waterline/img/favicon.png') }}">

    <title>Waterline{{ config('app.name') ? ' - ' . config('app.name') : '' }}</title>

    <!-- Style sheets-->
    <link id="app-stylesheet" href="{{ $cssUrl }}" rel="stylesheet">
    <link href="{{ $componentsCssUrl }}" rel="stylesheet">
</head>
<body>
<!-- Skip to main content link for keyboard navigation (WCAG 2.4.1) -->
<a href="#main-content" class="skip-link sr-only sr-only-focusable">
    Skip to main content
</a>

@if ($environmentBanner)
    <div class="environment-strip environment-strip--{{ $environmentBanner['colorClass'] }}">
        <div class="environment-strip__inner">
            <span class="environment-strip__swatch" aria-hidden="true"></span>
            <span class="environment-strip__label">{{ $environmentBanner['name'] }}</span>
        </div>
    </div>
@endif

<div
    id="waterline"
    data-waterline-config="{{ json_encode($waterlineBootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) }}"
    v-cloak></div>

<script type="module" src="{{ $jsUrl }}"></script>
</body>
</html>
