<?php

namespace Waterline\Http\Controllers;

use Illuminate\Support\Facades\App;
use RuntimeException;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\OperatorScope;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Waterline;

class DashboardController extends Controller
{
    private const ENVIRONMENT_COLOR_CLASSES = [
        '#0d6efd' => 'blue',
        '#2563eb' => 'blue',
        '#198754' => 'green',
        '#28a745' => 'green',
        '#6f42c1' => 'purple',
        '#7746ec' => 'purple',
        '#dc3545' => 'red',
        '#fd7e14' => 'orange',
        '#ffc107' => 'yellow',
    ];

    public function index()
    {
        $operatorScope = OperatorScope::payload();
        $backend = BackendConfiguration::serviceMode()
            ? app(RemoteBackend::class)->status()
            : BackendConfiguration::payload();
        $assetsAreCurrent = $this->assetsAreCurrent();
        $cssFile = 'app-dark.css';

        return view('waterline::layout', [
            'assetsAreCurrent' => $assetsAreCurrent,
            'cssUrl' => $this->assetUrl($cssFile),
            'componentsCssUrl' => $this->assetUrl('components.css'),
            'jsUrl' => $this->assetUrl('app.js'),
            'waterlineBootstrap' => [
                'path' => config('waterline.path', 'waterline'),
                'operator_scope' => $operatorScope,
                'backend' => $backend,
                'app_name' => config('app.name') ?: 'Workflow Operations',
                'assets_current' => $assetsAreCurrent,
                'maintenance' => App::isDownForMaintenance(),
            ],
            'operatorScope' => $operatorScope,
            'backend' => $backend,
            'environmentBanner' => $this->environmentBanner(),
            'isDownForMaintenance' => App::isDownForMaintenance(),
        ]);
    }

    private function assetsAreCurrent(): bool
    {
        try {
            return (bool) Waterline::assetsAreCurrent();
        } catch (RuntimeException) {
            return false;
        }
    }

    private function assetUrl(string $asset): string
    {
        $asset = ltrim($asset, '/');
        $manifestPath = public_path('vendor/waterline/mix-manifest.json');

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $versioned = is_array($manifest) ? $manifest['/'.$asset] ?? null : null;

            if (is_string($versioned) && $versioned !== '') {
                return asset('vendor/waterline/'.ltrim($versioned, '/'));
            }
        }

        return asset('vendor/waterline/'.$asset);
    }

    private function environmentBanner(): ?array
    {
        $name = trim((string) config('waterline.env_name', ''));

        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'colorClass' => $this->environmentColorClass((string) config('waterline.env_color', '#6c757d')),
        ];
    }

    private function environmentColorClass(string $color): string
    {
        return self::ENVIRONMENT_COLOR_CLASSES[strtolower(trim($color))] ?? 'neutral';
    }
}
