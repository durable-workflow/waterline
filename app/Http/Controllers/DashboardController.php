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

        return view('waterline::layout', [
            'assetsAreCurrent' => $this->assetsAreCurrent(),
            'cssFile' => true ? 'app-dark.css' : 'app.css',
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
