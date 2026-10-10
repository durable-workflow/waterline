import { createI18n } from 'vue-i18n';
import en from '../lang/en.json' with { type: 'json' };
import es from '../lang/es.json' with { type: 'json' };
import ja from '../lang/ja.json' with { type: 'json' };
import ptBR from '../lang/pt-BR.json' with { type: 'json' };
import uk from '../lang/uk.json' with { type: 'json' };
import zhHans from '../lang/zh-Hans.json' with { type: 'json' };

export const messages = { en, es, ja, 'pt-BR': ptBR, uk, 'zh-Hans': zhHans };

export function resolveUiLocale(locale) {
    if (typeof locale !== 'string') return 'en';
    const normalized = locale.toLowerCase().replaceAll('_', '-');
    if (normalized !== normalized.trim()) return 'en';
    if (['uk', 'uk-ua'].includes(normalized)) return 'uk';
    if (['pt', 'pt-br'].includes(normalized)) return 'pt-BR';
    if (/^ja(?:-(?:[a-z]{2}|[0-9]{3}))?$/.test(normalized)) return 'ja';
    if (['zh', 'zh-cn', 'zh-sg'].includes(normalized)
        || /^zh-hans(?:-(?:[a-z]{2}|[0-9]{3}))?$/.test(normalized)) return 'zh-Hans';
    return /^es(?:-(?:[a-z]{2}|[0-9]{3}))?$/.test(normalized) ? 'es' : 'en';
}

const ukrainianPlurals = new Intl.PluralRules('uk');
const spanishPlurals = new Intl.PluralRules('es');

export function spanishPluralRule(choice, choicesLength) {
    if (choicesLength !== 2) return choice === 1 ? 0 : Math.min(1, choicesLength - 1);
    return spanishPlurals.select(choice) === 'one' ? 0 : 1;
}

export function ukrainianPluralRule(choice, choicesLength) {
    // The catalog order is one | few | many | other. Fractional counts use
    // "other", so 1.5 cannot accidentally take the form used for one item.
    if (choicesLength !== 4) return choice === 1 ? 0 : Math.min(1, choicesLength - 1);
    return { one: 0, few: 1, many: 2, other: 3 }[ukrainianPlurals.select(choice)];
}

export function createWaterlineI18n(locale) {
    return createI18n({
        legacy: false,
        globalInjection: true,
        locale: resolveUiLocale(locale),
        fallbackLocale: 'en',
        messages,
        pluralRules: { es: spanishPluralRule, uk: ukrainianPluralRule },
        // Sentence keys keep the English interface legible in the source.
        // They are flat keys, including punctuation, rather than object paths.
        messageResolver: (catalog, key) => Object.hasOwn(catalog, key) ? catalog[key] : null,
        missingWarn: false,
        fallbackWarn: false,
    });
}

export function dialogLabels(translate) {
    return {
        confirmButtonText: translate('OK'),
        cancelButtonText: translate('Cancel'),
        denyButtonText: translate('No'),
        closeButtonAriaLabel: translate('Close'),
    };
}
