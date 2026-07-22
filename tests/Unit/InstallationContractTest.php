<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InstallationContractTest extends TestCase
{
    public function testDocumentedComposerCommandsRequireTheEntireExactBetaTrain(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents($root.'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $train = $manifest['extra']['durable-workflow']['product-train'] ?? null;

        $this->assertSame('2.0.0-beta.4', $train);
        $this->assertSame($train, $manifest['require']['durable-workflow/workflow'] ?? null);
        $this->assertSame($train, $manifest['require']['durable-workflow/sdk'] ?? null);

        $expectedPins = [
            'durable-workflow/waterline:'.$train.'@beta',
            'durable-workflow/workflow:'.$train.'@beta',
            'durable-workflow/sdk:'.$train.'@beta',
        ];
        $readme = (string) file_get_contents($root.'/README.md');
        preg_match_all('/```bash\n(composer require .*?)\n```/s', $readme, $matches);

        $this->assertNotEmpty($matches[1], 'README must expose at least one Composer installation command.');
        foreach ($matches[1] as $command) {
            foreach ($expectedPins as $pin) {
                $this->assertStringContainsString($pin, $command);
            }
        }
    }
}
