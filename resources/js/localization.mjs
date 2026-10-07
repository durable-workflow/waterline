import { createI18n } from 'vue-i18n';
import en from '../lang/en.json' with { type: 'json' };
import uk from '../lang/uk.json' with { type: 'json' };

export const messages = { en, uk };

export function resolveUiLocale(locale) {
    return typeof locale === 'string' && ['uk', 'uk-ua'].includes(locale.toLowerCase().replaceAll('_', '-')) ? 'uk' : 'en';
}

const ukrainianPlurals = new Intl.PluralRules('uk');

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
        pluralRules: { uk: ukrainianPluralRule },
        // Sentence keys keep the English interface legible in the source.
        // They are flat keys, including punctuation, rather than object paths.
        messageResolver: (catalog, key) => Object.hasOwn(catalog, key) ? catalog[key] : null,
        missingWarn: false,
        fallbackWarn: false,
    });
}
