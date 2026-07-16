<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use ReflectionObject;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Waterline\Tests\TestCase;
use Waterline\WaterlineServiceProvider;

final class ApiExceptionRendererTest extends TestCase
{
    protected function requiresDatabaseMigrations(): bool
    {
        return false;
    }

    public function testHostExceptionRendererUsesConfiguredWaterlinePathWithoutAffectingHostRoutes(): void
    {
        config()->set('waterline.path', 'custom-observer');

        Route::get('/custom-observer/api/debug-not-found', function (): void {
            throw (new ModelNotFoundException())->setModel('WorkflowRun');
        });
        Route::get('/debug-not-found', function (): void {
            throw (new ModelNotFoundException())->setModel('HostModel');
        });
        Route::get('/custom-observer/api/rate-limited', function (): void {
            throw new HttpException(429, 'Slow down.', null, ['Retry-After' => '30']);
        });

        $this->get('/custom-observer/api/debug-not-found')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson([
                'message' => 'Waterline API resource not found.',
                'error' => 'not_found',
            ]);

        $this->get('/custom-observer/api/rate-limited')
            ->assertStatus(429)
            ->assertHeader('content-type', 'application/json')
            ->assertHeader('retry-after', '30')
            ->assertExactJson([
                'message' => 'Slow down.',
                'error' => 'http_error',
            ]);

        $hostResponse = $this->get('/debug-not-found')->assertNotFound();

        $this->assertStringNotContainsString(
            'application/json',
            (string) $hostResponse->headers->get('content-type'),
        );
        $this->assertStringNotContainsString('Waterline API resource not found.', $hostResponse->getContent());
    }

    public function testRegisteringProviderAgainDoesNotDuplicateHostExceptionRenderer(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $callbacksBefore = $this->renderCallbackCount($handler);

        (new WaterlineServiceProvider($this->app))->register();
        (new WaterlineServiceProvider($this->app))->register();

        $this->assertGreaterThan(0, $callbacksBefore);
        $this->assertSame($callbacksBefore, $this->renderCallbackCount($handler));
    }

    private function renderCallbackCount(object $handler): int
    {
        $reflection = new ReflectionObject($handler);

        while (! $reflection->hasProperty('renderCallbacks')) {
            $reflection = $reflection->getParentClass();

            if ($reflection === false) {
                return 0;
            }
        }

        $callbacks = $reflection->getProperty('renderCallbacks')->getValue($handler);

        return is_array($callbacks) ? count($callbacks) : 0;
    }
}
