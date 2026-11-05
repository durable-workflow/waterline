"""Qualification recipe derivation and closed tool-source contracts, no build."""
import base64
import re

from contract import canonical, digest, exact, image_id, require, sha

DOCKERFILE = '4588c87344f6dd2133d3ab60d6cf278eb780db5827e1a2e3de9d90df720cfb4e'
VERSION = '1.64.0'
PACKAGES = {'playwright', 'playwright-core'}
BROWSERS = {'chromium', 'chromium-headless-shell', 'ffmpeg'}
PACKAGE_CLOSURE = 'c55f078e4ce950c03b979b76af199675a98dba5a0a4854cfa3100b80a48e86cc'
BROWSER_SOURCE = '2cfd419f71ac6475d81e1301fa86c34cf2a2a6bae2a0da4b5a2b18310216b6bf'


def derive(original):
    require(isinstance(original, bytes) and digest(original) == DOCKERFILE, 'Application recipe is not M1')
    text = original.decode()
    replacements = [
        ('WORKDIR /build/standalone',
         'ENV COMPOSER_MAX_PARALLEL_HTTP=2 COMPOSER_MAX_PARALLEL_PROCESSES=2\n\nWORKDIR /build/standalone'),
        ('RUN composer install \\\n', 'RUN composer install --no-plugins --no-autoloader --prefer-dist \\\n'),
        ('RUN composer dump-autoload \\\n', 'RUN --network=none composer dump-autoload --no-plugins \\\n'),
        ('RUN php -r \'require "/app/vendor/autoload.php";\'',
         'RUN --network=none php -r \'require "/app/vendor/autoload.php";\''),
        ('docker-php-ext-install mbstring', 'docker-php-ext-install -j2 mbstring'),
    ]
    for before, after in replacements:
        require(text.count(before) == 1, 'Ambiguous recipe transformation')
        text = text.replace(before, after, 1)
    return text.encode()


def validate_recipe(original, proposed):
    require(proposed == derive(original), 'Qualification recipe drift')
    # COPY/source/labels are inherited unchanged from the exact M1 recipe.
    return digest(proposed)


def validate_packages(packages):
    exact(packages, PACKAGES, 'Tool package closure is not closed')
    for name, item in packages.items():
        exact(item, ('version', 'resolved', 'integrity', 'dependencies'), 'Tool package record shape')
        require(item['version'] == VERSION
                and item['resolved'] == f'https://registry.npmjs.org/{name}/-/{name}-{VERSION}.tgz',
                'Tool package source changed')
        integrity = item['integrity']
        require(isinstance(integrity, str) and integrity.startswith('sha512-'), 'Tool integrity missing')
        try:
            raw = base64.b64decode(integrity[7:], validate=True)
        except ValueError as error:
            raise ValueError('Tool integrity invalid') from error
        require(len(raw) == 64, 'Tool integrity length')
        expected = {'playwright-core': VERSION} if name == 'playwright' else {}
        require(item['dependencies'] == expected, 'Unbound transitive tool package')
    require(digest(canonical(packages)) == PACKAGE_CLOSURE, 'Tool package integrity drift')


def validate_static_inputs(inputs, complete):
    exact(inputs, ('base_image_id', 'base_config_sha256', 'platform', 'library_inventory',
                   'browser_binary_hashes'), 'Static tool input shape')
    if not complete:
        require(all(value is None for value in inputs.values()), 'Partial tool selection is not admitted')
        return
    require(image_id(inputs['base_image_id']) and sha(inputs['base_config_sha256']), 'Tool base UNBOUND')
    require(inputs['platform'] in ('linux/amd64', 'linux/arm64'), 'Tool platform UNBOUND')
    libraries = inputs['library_inventory']
    require(isinstance(libraries, dict) and 0 < len(libraries) <= 512, 'Tool libraries UNBOUND')
    require(all(isinstance(key, str) and isinstance(value, str)
                and re.fullmatch(r'[a-zA-Z0-9.+_-]+', key) and 0 < len(value) <= 128
                for key, value in libraries.items()), 'Tool library identity invalid')
    exact(inputs['browser_binary_hashes'], BROWSERS, 'Browser binary identities UNBOUND')
    require(all(sha(value) for value in inputs['browser_binary_hashes'].values()), 'Browser hash UNBOUND')


def validate_tool(lock, complete=False):
    exact(lock, ('schema', 'node', 'python', 'upstream_commit', 'packages', 'browsers_source',
                 'browsers_source_sha256', 'browsers', 'unbound_static_inputs'), 'Tool lock shape')
    require(lock['schema'] == 'waterline.browser-tool-lock/v1' and lock['node'] == '24.22.1'
            and lock['python'] == '3.13'
            and lock['upstream_commit'] == '606ecd8b34bac100db32561b6278ffd76e5eb3cd', 'Tool source identity changed')
    validate_packages(lock['packages'])
    require(lock['browsers_source'] == 'https://raw.githubusercontent.com/microsoft/playwright/v1.64.0/packages/playwright-core/browsers.json'
            and lock['browsers_source_sha256'] == BROWSER_SOURCE, 'Browser source identity changed')
    browsers = lock['browsers']
    require(isinstance(browsers, list) and len(browsers) == 3, 'Browser closure shape')
    require({item.get('name') for item in browsers} == BROWSERS, 'Browser closure incomplete')
    for item in browsers:
        require(item.get('revision') == ('1013' if item['name'] == 'ffmpeg' else '1248'), 'Browser revision drift')
        if item['name'] != 'ffmpeg':
            require(item.get('browserVersion') == '156.0.8078.4', 'Chromium version drift')
    validate_static_inputs(lock['unbound_static_inputs'], complete)
