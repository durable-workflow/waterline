<?php

namespace Waterline\Http\Controllers;

use Illuminate\Support\Facades\App;
use RuntimeException;
use Waterline\Support\OperatorScope;
use Waterline\Waterline;

class DashboardController extends Controller
{
    public function index()
    {
        $operatorScope = OperatorScope::payload();
        $cssFile = 'app-dark.css';

        return view('waterline::layout', [
            'assetsAreCurrent' => $this->assetsAreCurrent(),
            'cssUrl' => $this->assetUrl($cssFile),
            'jsUrl' => $this->assetUrl('app.js'),
            'waterlineScriptVariables' => [
                'path' => config('waterline.path', 'waterline'),
                'operator_scope' => $operatorScope,
            ],
            'operatorScope' => $operatorScope,
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
            'color' => $this->safeCssColor((string) config('waterline.env_color', '#6c757d')),
        ];
    }

    private function safeCssColor(string $color): string
    {
        $color = trim($color);

        if (preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color) === 1) {
            return $color;
        }

        return '#6c757d';
    }
}
