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
        foreach (['zz', '', 'uk-UA<script>', ['uk'], false] as $locale) {
            self::assertSame('en', UiLocale::resolve($locale));
        }

        self::assertSame('uk', UiLocale::resolve('uk-UA'));
        self::assertSame('uk', UiLocale::resolve('UK_ua'));
        config()->set('waterline.locale', 'unsupported');
        $this->get('/waterline')->assertOk()->assertSee('<html lang="en">', false);
    }

    public function testSpanishBootstrapUsesUiLabelsWithoutChangingHostSettings(): void
    {
        foreach (['es', 'es-ES', 'es_MX', 'ES_ar', 'es-419'] as $locale) {
            self::assertSame('es', UiLocale::resolve($locale));
        }
        foreach (['es<script>', "es\n", "es-ES\n", ' es', "es-419\n"] as $locale) {
            self::assertSame('en', UiLocale::resolve($locale));
        }
        $this->app->setLocale('de');
        config()->set('waterline.locale', 'es');
        config()->set('app.name', '');

        $this->get('/waterline')->assertOk()
            ->assertSee('<html lang="es">', false)
            ->assertSee('Saltar al contenido principal')
            ->assertViewHas('waterlineBootstrap', fn (array $value): bool =>
                $value['locale'] === 'es' && $value['app_name'] === 'Operaciones de flujos de trabajo');

        self::assertSame('de', $this->app->getLocale());
    }

    public function testPortugueseBootstrapPreservesHostLocaleAndResolvesDocumentedAliases(): void
    {
        foreach (['pt', 'pt-BR', 'pt_br', 'PT_br'] as $locale) {
            self::assertSame('pt-BR', UiLocale::resolve($locale));
        }
        foreach (['pt-PT', 'pt<script>', "pt\n", "pt-BR\n", ' pt'] as $locale) {
            self::assertSame('en', UiLocale::resolve($locale));
        }
        $this->app->setLocale('de');
        config()->set('waterline.locale', 'PT_br');
        config()->set('app.name', '');

        $this->get('/waterline')->assertOk()
            ->assertSee('<html lang="pt-BR">', false)
            ->assertSee('Ir para o conteúdo principal')
            ->assertViewHas('waterlineBootstrap', fn (array $value): bool =>
                $value['locale'] === 'pt-BR' && $value['app_name'] === 'Operações de workflow');

        self::assertSame('de', $this->app->getLocale());
    }

    public function testChineseBootstrapUsesSimplifiedScriptWithoutChangingTheHost(): void
    {
        foreach (['zh', 'zh-Hans', 'ZH_hans', 'zh-CN', 'zh_sg', 'zh-Hans-CN', 'zh-Hans-US', 'zh-Hans-419'] as $locale) {
            self::assertSame('zh-Hans', UiLocale::resolve($locale));
        }
        foreach (['zh-Hant', 'zh-TW', 'zh-HK', 'zh-Hant-CN', 'zh-US', 'zh<script>', "zh-Hans\n", ' zh', "zh-Hans-419\n"] as $locale) {
            self::assertSame('en', UiLocale::resolve($locale));
        }
        $this->app->setLocale('fr');
        config()->set('waterline.locale', 'zh_CN');
        config()->set('app.name', '');

        $this->get('/waterline')->assertOk()
            ->assertSee('<html lang="zh-Hans">', false)
            ->assertSee('跳转到主要内容')
            ->assertViewHas('waterlineBootstrap', fn (array $value): bool =>
                $value['locale'] === 'zh-Hans' && $value['app_name'] === '工作流运维');

        self::assertSame('fr', $this->app->getLocale());
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
