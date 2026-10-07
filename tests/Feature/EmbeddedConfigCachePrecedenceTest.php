<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EmbeddedConfigCachePrecedenceTest extends TestCase
{
    public function testLiteralHostConfigurationSurvivesCacheGenerationAndCachedBootstrap(): void
    {
        if (! method_exists(\Illuminate\Foundation\Application::class, 'configure')) {
            $this->markTestSkipped('This real Laravel host fixture uses the Laravel 11+ application builder.');
        }

        $root = sys_get_temp_dir().'/waterline-embedded-config-'.bin2hex(random_bytes(8));
        $files = new Filesystem();
        $files->ensureDirectoryExists($root.'/bootstrap/cache');
        $files->ensureDirectoryExists($root.'/config');
        $repository = dirname(__DIR__, 2);

        try {
            file_put_contents($root.'/artisan', '<?php require '
                .var_export($repository.'/vendor/autoload.php', true).';'.PHP_EOL
                .'$app = require __DIR__."/bootstrap/app.php";'.PHP_EOL
                .'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));'.PHP_EOL);
            file_put_contents($root.'/bootstrap/app.php', <<<'PHP'
<?php
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        Workflow\Providers\WorkflowServiceProvider::class,
        Waterline\WaterlineServiceProvider::class,
    ])->create();
PHP);
            file_put_contents($root.'/config/waterline.php', <<<'PHP'
<?php
return ['backend' => 'embedded', 'engine_source' => 'v2', 'hybrid_migration_view' => false, 'locale' => 'uk'];
PHP);
            file_put_contents($root.'/config/workflows.php', <<<'PHP'
<?php
return ['v1' => ['enabled' => false]];
PHP);
            file_put_contents($root.'/inspect.php', '<?php require '
                .var_export($repository.'/vendor/autoload.php', true).';'.PHP_EOL
                .'$app = require __DIR__."/bootstrap/app.php";'.PHP_EOL
                .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'.PHP_EOL
                .'Waterline\\Support\\WorkflowEngineSourceResolver::status();'.PHP_EOL
                .'Waterline\\Support\\WorkflowEngineSourceResolver::status();'.PHP_EOL
                .'echo json_encode(['.PHP_EOL
                .'    "v1_enabled" => config("workflows.v1.enabled"),'.PHP_EOL
                .'    "engine_source" => config("waterline.engine_source"),'.PHP_EOL
                .'    "hybrid_migration_view" => config("waterline.hybrid_migration_view"),'.PHP_EOL
                .'    "locale" => config("waterline.locale"),'.PHP_EOL
                .'], JSON_THROW_ON_ERROR);'.PHP_EOL);

            $environment = [
                'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/config.php',
                'APP_KEY' => 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'WATERLINE_ENGINE_SOURCE' => 'v1',
                'WATERLINE_HYBRID_MIGRATION_VIEW' => 'true',
                'WATERLINE_RUNTIME_ENVIRONMENT_OVERRIDES' => 'false',
                'WATERLINE_LOCALE' => 'en',
            ];
            $expected = [
                'v1_enabled' => false,
                'engine_source' => 'v2',
                'hybrid_migration_view' => false,
                'locale' => 'uk',
            ];

            $this->assertSame($expected, $this->inspect($root, $environment));

            (new Process([PHP_BINARY, $root.'/artisan', 'config:cache', '--no-interaction'], $root, $environment))
                ->mustRun();
            $cached = require $environment['APP_CONFIG_CACHE'];
            $this->assertSame(false, $cached['workflows']['v1']['enabled']);
            $this->assertSame('v2', $cached['waterline']['engine_source']);
            $this->assertSame(false, $cached['waterline']['hybrid_migration_view']);
            $this->assertSame('uk', $cached['waterline']['locale']);
            $this->assertSame($expected, $this->inspect($root, $environment));
        } finally {
            $files->deleteDirectory($root);
        }
    }

    /** @param array<string, string> $environment */
    private function inspect(string $root, array $environment): array
    {
        $process = new Process([PHP_BINARY, $root.'/inspect.php'], $root, $environment);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
