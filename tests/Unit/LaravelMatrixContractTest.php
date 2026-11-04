<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LaravelMatrixContractTest extends TestCase
{
    public function testInstalledCheckAcceptsTheLaravel9ComposerOverrideButRejectsAnUnlistedCell(): void
    {
        $source = dirname(__DIR__, 2);
        $root = sys_get_temp_dir().'/waterline-matrix-'.bin2hex(random_bytes(8));
        mkdir($root.'/scripts/ci', 0700, true);
        mkdir($root.'/.github', 0700);
        copy($source.'/scripts/ci/laravel-matrix.php', $root.'/scripts/ci/laravel-matrix.php');
        copy($source.'/.github/laravel-matrix.json', $root.'/.github/laravel-matrix.json');
        copy($source.'/composer.json', $root.'/composer.json');
        copy($source.'/testbench.yaml', $root.'/testbench.yaml');

        try {
            $matrix = json_decode((string) file_get_contents($root.'/.github/laravel-matrix.json'), true, 512, JSON_THROW_ON_ERROR);
            // The enclosing job may already have replaced its root manifest
            // with the Laravel 9 override. Start this fixture before that step.
            $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $manifest['require-dev']['durable-workflow/workflow'] = $matrix['include'][1]['workflow'];
            file_put_contents($root.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $options = [];
            foreach (['laravel', 'testbench', 'workbench', 'php', 'phpunit', 'workflow', 'scaffold', 'resolution'] as $key) {
                $options[] = '--'.$key.'='.$matrix['include'][0][$key];
            }

            $validate = new Process([PHP_BINARY, $root.'/scripts/ci/laravel-matrix.php', 'validate', ...$options]);
            $validate->run();
            $this->assertSame(0, $validate->getExitCode(), $validate->getErrorOutput());

            // This is the rewrite performed by composer require --dev in the
            // Laravel 9 job, while the source matrix keeps the current pins.
            $manifest['require-dev']['durable-workflow/workflow'] = $matrix['include'][0]['workflow'];
            file_put_contents($root.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));

            $installed = new Process([PHP_BINARY, $root.'/scripts/ci/laravel-matrix.php', 'assert-installed', ...$options]);
            $installed->run();
            $this->assertSame(1, $installed->getExitCode());
            $this->assertStringContainsString('Dependencies are not installed', $installed->getErrorOutput());

            $options[5] = '--workflow='.$matrix['include'][1]['workflow'];
            $invalid = new Process([PHP_BINARY, $root.'/scripts/ci/laravel-matrix.php', 'assert-installed', ...$options]);
            $invalid->run();
            $this->assertSame(1, $invalid->getExitCode());
            $this->assertStringContainsString('Unsupported Laravel 9 dependency tuple', $invalid->getErrorOutput());
        } finally {
            unlink($root.'/scripts/ci/laravel-matrix.php');
            unlink($root.'/.github/laravel-matrix.json');
            unlink($root.'/composer.json');
            unlink($root.'/testbench.yaml');
            rmdir($root.'/scripts/ci');
            rmdir($root.'/scripts');
            rmdir($root.'/.github');
            rmdir($root);
        }
    }
}
