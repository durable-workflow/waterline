import assert from 'node:assert/strict';
import test from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { baseCompile } from '@intlify/message-compiler';
import { createWaterlineI18n, dialogLabels, frenchPluralRule, messages, resolveUiLocale, ukrainianPluralRule } from '../../resources/js/localization.mjs';
import { localizedState } from '../../resources/js/state-labels.mjs';

test('every catalog covers the same messages and every translation compiles', () => {
    for (const locale of Object.keys(messages)) {
        assert.deepEqual(Object.keys(messages[locale]).sort(), Object.keys(messages.en).sort(), locale);
        const i18n = createWaterlineI18n(locale);
        for (const key of Object.keys(messages.en)) {
            assert.equal(typeof messages[locale][key], 'string', `${locale}: ${key}`);
            assert.ok(messages[locale][key].trim().length, `${locale}: ${key}`);
            const message = messages[locale][key];
            baseCompile(message, { onError: error => { throw error; } });
            if (!message.includes('{') && !message.includes('|')) assert.equal(i18n.global.t(key), message, `${locale}: ${key}`);
            const placeholders = text => [...new Set([...text.matchAll(/\{(\w+)\}/g)].map(match => match[1]))].sort();
            assert.deepEqual(placeholders(message), placeholders(messages.en[key]), `${locale}: ${key}`);
        }
    }
});

test('presentation labels leave machine states and unknown identifiers intact', () => {
    const translate = createWaterlineI18n('uk').global.t;
    const state = { status: 'cancelled', run_id: 'cancelled', future: 'future_state' };
    const before = structuredClone(state);
    assert.equal(localizedState(state.status, translate), 'Скасовано');
    assert.equal(localizedState('cleaning_up', translate), 'Очищення');
    assert.equal(localizedState(state.future, translate), 'future_state');
    assert.deepEqual(state, before);
});

test('dialog defaults include translated cancellation and accessible close controls', () => {
    const labels = dialogLabels(createWaterlineI18n('uk').global.t);
    assert.equal(labels.confirmButtonText, 'Гаразд');
    assert.equal(labels.cancelButtonText, 'Скасувати');
    assert.equal(labels.closeButtonAriaLabel, 'Закрити');
    assert.equal(labels.denyButtonText, 'Ні');
});

test('the operator locale defaults to English and accepts the documented Ukrainian locale', () => {
    for (const locale of [undefined, null, '', 'zz', 'uk<script>', ['uk']]) assert.equal(resolveUiLocale(locale), 'en');
    for (const locale of ['uk', 'uk-UA', 'UK_ua']) assert.equal(resolveUiLocale(locale), 'uk');
    assert.equal(createWaterlineI18n().global.t('Skip to main content'), 'Skip to main content');
    assert.equal(createWaterlineI18n('uk').global.t('Skip to main content'), 'Перейти до основного вмісту');
});

test('Spanish regional aliases select operator translations and safe command labels', () => {
    for (const locale of ['es', 'es-ES', 'es_MX', 'ES_ar', 'es-419']) assert.equal(resolveUiLocale(locale), 'es');
    for (const locale of ['es<script>', 'es\n', 'es-ES\n', ' es', 'es-419\n']) assert.equal(resolveUiLocale(locale), 'en');
    const translate = createWaterlineI18n('es').global.t;
    assert.equal(translate('Skip to main content'), 'Saltar al contenido principal');
    assert.equal(localizedState('cleaning_up', translate), 'Limpieza en curso');
    assert.equal(localizedState('cancelled', translate), 'Cancelado');
    assert.equal(dialogLabels(translate).cancelButtonText, 'Cancelar');
    assert.equal(dialogLabels(translate).closeButtonAriaLabel, 'Cerrar');
    assert.equal(localizedState('future_state', translate), 'future_state');
});

test('Spanish count messages distinguish exactly one from zero, fractions and large counts', () => {
    const translate = createWaterlineI18n('es').global.t;
    for (const [count, expected] of [[0, '0 ejecuciones'], [1, '1 ejecución'], [2, '2 ejecuciones'],
        [0.5, '0.5 ejecuciones'], [1.5, '1.5 ejecuciones'], [1000000, '1000000 ejecuciones']]) {
        assert.equal(translate('{count} runs', count), expected);
    }
    assert.equal(translate('{count} children shown{more}', { count: 1, more: '' }), 'Se muestra 1 flujo hijo');
    assert.equal(translate('{count} children shown{more}', { count: 2, more: ', más' }), 'Se muestran 2 flujos hijos, más');
});

test('French aliases preserve diagnostic values and separate cancellation from force termination', () => {
    for (const locale of ['fr', 'fr-FR', 'FR_ca', 'fr-CH', 'fr-029']) assert.equal(resolveUiLocale(locale), 'fr');
    for (const locale of ['fr<script>', 'fr\n', 'fr-FR\n', ' fr', 'fr-Latn', 'fr-Latn-FR']) assert.equal(resolveUiLocale(locale), 'en');
    const translate = createWaterlineI18n('fr').global.t;
    assert.equal(translate('Skip to main content'), 'Aller au contenu principal');
    assert.equal(localizedState('cleaning_up', translate), 'Nettoyage en cours');
    assert.equal(localizedState('cancelled', translate), 'Annulé');
    assert.equal(localizedState('terminated', translate), 'Interrompu de force');
    assert.equal(localizedState('lost_authority', translate), 'Autorité perdue');
    assert.equal(localizedState('future_state', translate), 'future_state');
    assert.equal(dialogLabels(translate).cancelButtonText, 'Annuler');
    assert.equal(dialogLabels(translate).closeButtonAriaLabel, 'Fermer');
    assert.equal(translate('Request failed with HTTP {value1}: {value2}', { value1: 503, value2: 'Original <exception> / 原文' }),
        'Échec de la requête avec HTTP 503 : Original <exception> / 原文');
    assert.equal(translate('Acknowledged {at} after the original deadline', { at: '2026-10-02T00:00:31Z' }),
        "Réception confirmée à 2026-10-02T00:00:31Z, après l'échéance initiale");
    assert.match(translate('This immediately closes the current active run as Cancelled without cooperative cleanup. Previously completed external side effects are not undone.'),
        /Annulé \(Cancelled\), sans nettoyage coopératif/);
    assert.match(translate('This forcibly closes the current active run as Terminated and stops cooperative cleanup. Previously completed external side effects are not undone.'),
        /Interrompu de force \(Terminated\) et arrête le nettoyage coopératif/);
});

test('French count rules include zero and small fractions in one, and numeric millions in plural', () => {
    const translate = createWaterlineI18n('fr').global.t;
    for (const [count, expected] of [[0, '0 exécution'], [1, '1 exécution'], [2, '2 exécutions'],
        [0.5, '0.5 exécution'], [1.5, '1.5 exécution'], [2.5, '2.5 exécutions'], [1000000, '1000000 exécutions']]) {
        assert.equal(translate('{count} runs', count), expected);
    }
    assert.equal(translate('{count} children shown{more}', { count: 1, more: '' }), '1 workflow enfant affiché');
    assert.equal(translate('{count} children shown{more}', { count: 2, more: ', autres' }), '2 workflows enfants affichés, autres');
    assert.equal(frenchPluralRule(1, 1), 0);
    assert.equal(frenchPluralRule(2, 1), 0);
});

test('missing Ukrainian messages fall back to English and unknown keys stay readable', () => {
    const i18n = createWaterlineI18n('uk');
    i18n.global.mergeLocaleMessage('en', { 'Fallback.': 'Available in English' });
    assert.equal(i18n.global.t('Fallback.'), 'Available in English');
    assert.equal(i18n.global.t('Unknown key.'), 'Unknown key.');
});

test('Brazilian Portuguese aliases preserve machine states and distinguish force termination', () => {
    for (const locale of ['pt', 'pt-BR', 'pt_br', 'PT_br']) assert.equal(resolveUiLocale(locale), 'pt-BR');
    for (const locale of ['pt-PT', 'pt<script>', 'pt\n', 'pt-BR\n', ' pt']) assert.equal(resolveUiLocale(locale), 'en');
    const translate = createWaterlineI18n('pt-BR').global.t;
    assert.equal(translate('Skip to main content'), 'Ir para o conteúdo principal');
    assert.equal(localizedState('cleaning_up', translate), 'Limpeza em andamento');
    assert.equal(localizedState('cancelled', translate), 'Cancelado');
    assert.equal(localizedState('terminated', translate), 'Encerrado à força');
    assert.equal(localizedState('future_state', translate), 'future_state');
    assert.equal(dialogLabels(translate).cancelButtonText, 'Cancelar');
    assert.equal(dialogLabels(translate).closeButtonAriaLabel, 'Fechar');
    assert.equal(translate('Request failed with HTTP {value1}: {value2}', { value1: 503, value2: 'Original <exception>' }),
        'A solicitação falhou com HTTP 503: Original <exception>');
});

test('Portuguese item counts use the singular for one and preserve count parameters', () => {
    // These are counts of discrete runs/items, including zero and fractional
    // diagnostic input. Vue I18n's one | other rule supplies the intended forms.
    const translate = createWaterlineI18n('pt-BR').global.t;
    for (const [count, expected] of [[0, '0 execuções'], [1, '1 execução'], [2, '2 execuções'],
        [0.5, '0.5 execuções'], [1.5, '1.5 execuções'], [1000000, '1000000 execuções']]) {
        assert.equal(translate('{count} runs', count), expected);
    }
    assert.equal(translate('{count} children shown{more}', { count: 1, more: '' }), 'Exibido 1 filho');
    assert.equal(translate('{count} children shown{more}', { count: 2, more: ', há mais' }), 'Exibidos 2 filhos, há mais');
});

test('Simplified Chinese aliases keep Traditional Chinese unsupported and preserve machine values', () => {
    for (const locale of ['zh', 'zh-Hans', 'ZH_hans', 'zh-CN', 'zh_sg', 'zh-Hans-CN', 'zh-Hans-US', 'zh-Hans-419']) {
        assert.equal(resolveUiLocale(locale), 'zh-Hans');
    }
    for (const locale of ['zh-Hant', 'zh-TW', 'zh-HK', 'zh-Hant-CN', 'zh-US', 'zh<script>', 'zh-Hans\n', ' zh', 'zh-Hans-419\n']) {
        assert.equal(resolveUiLocale(locale), 'en');
    }
    const translate = createWaterlineI18n('zh-Hans').global.t;
    assert.equal(translate('Skip to main content'), '跳转到主要内容');
    assert.equal(localizedState('cleaning_up', translate), '正在清理');
    assert.equal(localizedState('cancelled', translate), '已取消');
    assert.equal(localizedState('terminated', translate), '已强制终止');
    assert.equal(localizedState('lost_authority', translate), '已失去执行权');
    assert.equal(localizedState('future_state', translate), 'future_state');
    assert.equal(dialogLabels(translate).cancelButtonText, '取消');
    assert.equal(dialogLabels(translate).closeButtonAriaLabel, '关闭');
    assert.equal(translate('Request failed with HTTP {value1}: {value2}', { value1: 503, value2: 'Original <exception> / 原文' }),
        '请求失败，HTTP 503：Original <exception> / 原文');
});

test('Chinese counts use one form without changing count or lineage parameters', () => {
    const translate = createWaterlineI18n('zh-Hans').global.t;
    for (const count of [0, 1, 2, 0.5, 1.5, 1000000]) {
        assert.equal(translate('{count} runs', count), `${count} 个运行`);
        assert.equal(translate('{count} workers', count), `${count} 个工作进程`);
    }
    assert.equal(translate('{count} children shown{more}', { count: 2, more: '，完整详情中还有更多' }),
        '显示 2 个子工作流，完整详情中还有更多');
    assert.equal(translate('Acknowledged {at} after the original deadline', { at: '2026-10-02T00:00:31Z' }),
        '于 2026-10-02T00:00:31Z 确认，晚于原始截止时间');
    assert.equal(translate('This immediately closes the current active run as Cancelled without cooperative cleanup. Previously completed external side effects are not undone.'),
        '此操作会立即将当前活跃运行关闭为已取消（Cancelled），不会执行协作式清理。此前已完成的外部副作用不会撤销。');
});

test('Japanese aliases preserve original diagnostics and distinguish force termination', () => {
    for (const locale of ['ja', 'ja-JP', 'JA_jp', 'ja-US', 'ja-419']) assert.equal(resolveUiLocale(locale), 'ja');
    for (const locale of ['ja<script>', 'ja\n', 'ja-JP\n', ' ja', 'ja-Latn', 'ja-Jpan-JP']) assert.equal(resolveUiLocale(locale), 'en');
    const translate = createWaterlineI18n('ja').global.t;
    assert.equal(translate('Skip to main content'), 'メインコンテンツへ移動');
    assert.equal(localizedState('cleaning_up', translate), 'クリーンアップ中');
    assert.equal(localizedState('cancelled', translate), 'キャンセル済み');
    assert.equal(localizedState('terminated', translate), '強制終了済み');
    assert.equal(localizedState('lost_authority', translate), '処理権限の喪失');
    assert.equal(localizedState('future_state', translate), 'future_state');
    assert.equal(dialogLabels(translate).cancelButtonText, 'キャンセル');
    assert.equal(dialogLabels(translate).closeButtonAriaLabel, '閉じる');
    assert.equal(translate('Request failed with HTTP {value1}: {value2}', { value1: 503, value2: 'Original <exception> / 原文' }),
        '要求がHTTP 503で失敗しました: Original <exception> / 原文');
});

test('Japanese counts use one form and retain the original cancellation deadline', () => {
    const translate = createWaterlineI18n('ja').global.t;
    for (const count of [0, 1, 2, 0.5, 1.5, 1000000]) {
        assert.equal(translate('{count} runs', count), `${count}件の実行`);
        assert.equal(translate('{count} workers', count), `${count}件のワーカー`);
    }
    assert.equal(translate('{count} children shown{more}', { count: 2, more: '、ほかにもあります' }),
        '2件の子ワークフローを表示、ほかにもあります');
    assert.equal(translate('Acknowledged {at} after the original deadline', { at: '2026-10-02T00:00:31Z' }),
        '元の期限を過ぎた2026-10-02T00:00:31Zに受領確認');
    assert.equal(translate('This immediately closes the current active run as Cancelled without cooperative cleanup. Previously completed external side effects are not undone.'),
        '現在の稼働中の実行を直ちにキャンセル済み (Cancelled) として終了します。協調的なクリーンアップは実行しません。すでに完了した外部の副作用は取り消されません。');
});

test('Ukrainian plurals distinguish one, few, many, and fractional counts', () => {
    const i18n = createWaterlineI18n('uk');
    for (const [count, expected] of [[0, '0 запусків'], [1, '1 запуск'], [2, '2 запуски'], [5, '5 запусків'],
        [11, '11 запусків'], [21, '21 запуск'], [22, '22 запуски'], [25, '25 запусків'], [101, '101 запуск'], [1.5, '1.5 запуску']]) {
        assert.equal(i18n.global.t('{count} runs', count), expected);
    }
    assert.equal(ukrainianPluralRule(1, 2), 0);
    assert.equal(ukrainianPluralRule(2, 2), 1);
});

test('translated parameters render as text and retain the original data', async () => {
    const i18n = createWaterlineI18n('uk');
    i18n.global.mergeLocaleMessage('uk', { 'Result for {name}': 'Результат для {name}' });
    const name = '<script>alert("unchanged")</script> & payload';
    const app = createSSRApp({ template: '<p>{{ $t("Result for {name}", { name }) }}</p>', data: () => ({ name }) });
    app.use(i18n);
    const html = await renderToString(app);
    assert.ok(html.includes('Результат для &lt;script&gt;'));
    assert.ok(!html.includes('<script>'));
    assert.ok(!html.includes('&amp;lt;'));
    assert.equal(i18n.global.t('Result for {name}', { name }), 'Результат для ' + name);
});
