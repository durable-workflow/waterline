import assert from 'node:assert/strict'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import * as sass from 'sass'

// Compiles each shipped theme and checks the contrast of its shared text,
// status, badge and outline tokens against the surfaces they render on.
// Browser checks measure rendered pages; this check pins the token values.

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const MINIMUM = 4.5
// Status pills and banners tint their background with up to 18% of the
// status color.
const STATUS_TINT = 0.18

function compile(theme) {
    return sass.compile(path.join(root, `resources/sass/${theme}.scss`), {
        loadPaths: [path.join(root, 'node_modules')],
        quietDeps: true,
        silenceDeprecations: ['abs-percent', 'color-functions', 'global-builtin', 'if-function', 'import'],
        style: 'expanded',
    }).css
}

function parseColor(value) {
    const text = value.trim().toLowerCase()
    const hex = text.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/)

    if (hex) {
        const digits = hex[1].length === 3 ? [...hex[1]].map((digit) => digit + digit).join('') : hex[1]

        return { r: parseInt(digits.slice(0, 2), 16), g: parseInt(digits.slice(2, 4), 16), b: parseInt(digits.slice(4, 6), 16), a: 1 }
    }

    const rgb = text.match(/^rgba?\(([^)]+)\)$/)

    if (rgb) {
        const [r, g, b, a = 1] = rgb[1].split(/[\s,/]+/).filter(Boolean).map(Number)

        return { r, g, b, a }
    }

    if (text === 'white' || text === '#fff') return { r: 255, g: 255, b: 255, a: 1 }

    throw new Error(`Unsupported color: ${value}`)
}

function over(foreground, background) {
    return {
        r: foreground.r * foreground.a + background.r * (1 - foreground.a),
        g: foreground.g * foreground.a + background.g * (1 - foreground.a),
        b: foreground.b * foreground.a + background.b * (1 - foreground.a),
        a: 1,
    }
}

function tint(color, background, weight) {
    return over({ ...color, a: weight }, background)
}

function luminance(color) {
    const channel = (value) => {
        const normalized = value / 255

        return normalized <= 0.04045 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4
    }

    return 0.2126 * channel(color.r) + 0.7152 * channel(color.g) + 0.0722 * channel(color.b)
}

function contrast(first, second) {
    const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a)

    return (light + 0.05) / (dark + 0.05)
}

// The declarations of every rule whose selector list contains `selector`, in
// source order, so later rules win as they do in the browser.
function declarations(css, selector) {
    const result = {}
    const rule = /([^{}]+)\{([^{}]*)\}/g
    let match

    while ((match = rule.exec(css)) !== null) {
        const selectors = match[1].split(',').map((item) => item.trim())

        if (!selectors.includes(selector)) continue

        for (const declaration of match[2].split(';')) {
            const separator = declaration.indexOf(':')

            if (separator === -1) continue

            const property = declaration.slice(0, separator).trim()
            result[property] = declaration.slice(separator + 1).replace(/!important/, '').trim()
        }
    }

    return result
}

function rootTokens(css) {
    const block = [...css.matchAll(/:root\s*\{([^{}]*)\}/g)].map((match) => match[1]).find((body) => body.includes('--wl-text:'))
    assert.ok(block, 'theme defines the Waterline tokens')

    return Object.fromEntries(block.split(';').map((item) => item.split(':').map((part) => part.trim())).filter(([name]) => name?.startsWith('--wl-')))
}

function failures(pairs) {
    return pairs
        .map(([label, foreground, background]) => ({ label, ratio: contrast(foreground, background) }))
        .filter(({ ratio }) => ratio < MINIMUM)
        .map(({ label, ratio }) => `${label}: ${(Math.floor(ratio * 100) / 100).toFixed(2)}:1`)
}

for (const theme of ['app', 'app-dark']) {
    const css = compile(theme)
    const tokens = rootTokens(css)
    const color = (name) => parseColor(tokens[name])
    const surfaces = {
        page: color('--wl-bg'),
        card: color('--wl-surface'),
        'muted card': over(color('--wl-surface-muted'), color('--wl-surface')),
        'table header': over(color('--wl-surface-emphasis'), color('--wl-surface')),
    }
    const onSurfaces = (label, foreground) => Object.entries(surfaces).map(([surface, background]) => [`${label} on ${surface}`, foreground, background])

    test(`${theme}: secondary text tokens are solid and readable on every surface`, () => {
        for (const name of ['--wl-text-muted', '--wl-text-soft']) {
            assert.equal(color(name).a, 1, `${name} is a solid color`)
        }

        assert.deepEqual(failures([
            ...onSurfaces('--wl-text', color('--wl-text')),
            ...onSurfaces('--wl-text-muted', color('--wl-text-muted')),
            ...onSurfaces('--wl-text-soft', color('--wl-text-soft')),
        ]), [])
    })

    test(`${theme}: primary, muted and soft text keep their visual order`, () => {
        const card = surfaces.card
        const primary = contrast(color('--wl-text'), card)
        const muted = contrast(color('--wl-text-muted'), card)
        const soft = contrast(color('--wl-text-soft'), card)

        assert.ok(primary > muted && muted > soft, `text ${primary.toFixed(2)} > muted ${muted.toFixed(2)} > soft ${soft.toFixed(2)}`)
    })

    test(`${theme}: status and accent text colors are readable plain and on their own tint`, () => {
        const pairs = []

        for (const name of ['--wl-success', '--wl-danger', '--wl-warning', '--wl-info', '--wl-accent']) {
            pairs.push(...onSurfaces(name, color(name)))
            pairs.push([`${name} on its tinted card`, color(name), tint(color(name), surfaces.card, STATUS_TINT)])
            pairs.push([`${name} on its tinted muted card`, color(name), tint(color(name), surfaces['muted card'], STATUS_TINT)])
        }

        assert.deepEqual(failures(pairs), [])
    })

    test(`${theme}: text utilities and outline controls use readable status colors`, () => {
        const pairs = []

        for (const variant of ['success', 'danger', 'warning', 'info']) {
            pairs.push(...onSurfaces(`.text-${variant}`, parseColor(declarations(css, `.text-${variant}`).color)))
            pairs.push(...onSurfaces(`.btn-outline-${variant}`, parseColor(declarations(css, `.btn-outline-${variant}`).color)))
        }

        pairs.push(...onSurfaces('.btn-outline-secondary', parseColor(declarations(css, '.btn-outline-secondary').color)))

        assert.deepEqual(failures(pairs), [])
    })

    test(`${theme}: status badges carry readable text`, () => {
        const pairs = ['success', 'danger', 'warning', 'info', 'primary', 'secondary', 'dark'].map((variant) => {
            const rule = declarations(css, `.badge-${variant}`)

            return [`.badge-${variant}`, parseColor(rule.color), parseColor(rule['background-color'])]
        })

        assert.deepEqual(failures(pairs), [])
    })

    test(`${theme}: text inside dark code surfaces uses the code text tokens`, () => {
        const rule = declarations(css, '.wl-main .code-bg')
        const surface = over(parseColor(rule.background), surfaces.card)
        const pairs = ['--wl-text', '--wl-text-muted', '--wl-text-soft'].map((name) => {
            assert.ok(rule[name], `.wl-main .code-bg sets ${name}`)

            return [`${name} on code surface`, parseColor(rule[name]), surface]
        })

        const header = over(parseColor(rule['--wl-surface-emphasis']), surface)
        pairs.push(['--wl-text-soft on a code table header', parseColor(rule['--wl-text-soft']), header])
        pairs.push(['outline primary on code surface', parseColor(declarations(css, '.wl-main .code-bg .btn-outline-primary').color), surface])

        assert.deepEqual(failures(pairs), [])
    })
}
