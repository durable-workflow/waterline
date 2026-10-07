import assert from 'node:assert/strict';
import test from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { baseCompile } from '@intlify/message-compiler';
import { createWaterlineI18n, dialogLabels, messages, resolveUiLocale, ukrainianPluralRule } from '../../resources/js/localization.mjs';
import { localizedState } from '../../resources/js/state-labels.mjs';

test('both catalogs cover the same messages and every translation compiles', () => {
    assert.deepEqual(Object.keys(messages.uk).sort(), Object.keys(messages.en).sort());
    for (const locale of ['en', 'uk']) {
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
    for (const locale of [undefined, null, '', 'es', 'uk<script>', ['uk']]) assert.equal(resolveUiLocale(locale), 'en');
    for (const locale of ['uk', 'uk-UA', 'UK_ua']) assert.equal(resolveUiLocale(locale), 'uk');
    assert.equal(createWaterlineI18n().global.t('Skip to main content'), 'Skip to main content');
    assert.equal(createWaterlineI18n('uk').global.t('Skip to main content'), 'Перейти до основного вмісту');
});

test('missing Ukrainian messages fall back to English and unknown keys stay readable', () => {
    const i18n = createWaterlineI18n('uk');
    i18n.global.mergeLocaleMessage('en', { 'Fallback.': 'Available in English' });
    assert.equal(i18n.global.t('Fallback.'), 'Available in English');
    assert.equal(i18n.global.t('Unknown key.'), 'Unknown key.');
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
