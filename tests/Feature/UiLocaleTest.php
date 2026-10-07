<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Waterline\Support\UiLocale;
use Waterline\Tests\TestCase;

final class UiLocaleTest extends TestCase
{
    protected function requiresDatabaseMigrations(): bool
    {
        return false;
    }

    public function testDefaultEnglishDoesNotFollowOrChangeTheHostLocale(): void
    {
        $this->app->setLocale('uk');
        config()->set('app.name', '');

        $this->get('/waterline')->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Skip to main content')
            ->assertViewHas('waterlineBootstrap', fn (array $value): bool =>
                $value['locale'] === 'en' && $value['app_name'] === 'Workflow Operations');

        self::assertSame('uk', $this->app->getLocale());
    }

    public function testEmbeddedUkrainianBootstrapAndKeyboardLinkKeepApplicationNamesExact(): void
    {
        $this->app->setLocale('fr');
        config()->set('waterline.locale', 'uk');
        config()->set('app.name', 'Customer <script>alert("name")</script>');

        $response = $this->get('/waterline')->assertOk()
            ->assertSee('<html lang="uk">', false)
            ->assertSee('Перейти до основного вмісту')
            ->assertDontSee('<script>alert("name")</script>', false)
            ->assertViewHas('waterlineBootstrap', fn (array $value): bool =>
                $value['locale'] === 'uk' && $value['app_name'] === 'Customer <script>alert("name")</script>');

        self::assertSame('fr', $this->app->getLocale());
        self::assertStringContainsString('\\u003Cscript\\u003E', $response->getContent());
    }

    public function testDefaultApplicationTitleUsesTheUiLocale(): void
    {
        config()->set('app.name', '');
        config()->set('waterline.locale', 'uk');

        $this->get('/waterline')->assertOk()
            ->assertViewHas('waterlineBootstrap', fn (array $value): bool =>
                $value['app_name'] === 'Керування робочими процесами');
    }

    public function testUnknownLocaleFallsBackToEnglishAndSupportedRegionalAliasResolves(): void
    {
        foreach (['es', '', 'uk-UA<script>', ['uk'], false] as $locale) {
            self::assertSame('en', UiLocale::resolve($locale));
        }

        self::assertSame('uk', UiLocale::resolve('uk-UA'));
        self::assertSame('uk', UiLocale::resolve('UK_ua'));
        config()->set('waterline.locale', 'unsupported');
        $this->get('/waterline')->assertOk()->assertSee('<html lang="en">', false);
    }

    public function testMissingTranslationFallsBackToEnglishWithoutUsingHostMessages(): void
    {
        config()->set('waterline.locale', 'uk');
        $this->app->setLocale('fr');
        $translator = $this->app->make('translator');
        $translator->addLines(['ui.Only in English' => 'English fallback'], 'en', 'waterline');
        $translator->addLines(['ui.Only in English' => 'Host message'], 'fr', 'waterline');

        self::assertSame('English fallback', UiLocale::text('Only in English'));
        self::assertSame('Unknown key', UiLocale::text('Unknown key'));
        self::assertSame('fr', $this->app->getLocale());
    }
}
