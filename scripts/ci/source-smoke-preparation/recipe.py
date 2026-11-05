"""Qualification recipe derivation and closed tool-source contracts, no build."""
import base64
import io
import re
import tarfile
import unicodedata
from dataclasses import dataclass

from contract import Refusal, canonical, digest, exact, image_id, positive, require, sha

DOCKERFILE = '4588c87344f6dd2133d3ab60d6cf278eb780db5827e1a2e3de9d90df720cfb4e'
VERSION = '1.64.0'
PACKAGES = {'playwright', 'playwright-core'}
BROWSERS = {'chromium', 'chromium-headless-shell', 'ffmpeg'}
PACKAGE_CLOSURE = 'c55f078e4ce950c03b979b76af199675a98dba5a0a4854cfa3100b80a48e86cc'
BROWSER_SOURCE = '2cfd419f71ac6475d81e1301fa86c34cf2a2a6bae2a0da4b5a2b18310216b6bf'
DIRECTORY = 'directory'


def derive(original):
    require(isinstance(original, bytes) and digest(original) == DOCKERFILE, 'Application recipe is not M1')
    text = original.decode()
    replacements = [
        ('FROM composer:2.10.3@', 'FROM composer@'),
        ('FROM php:8.3.36-cli-alpine@', 'FROM php@'),
        ('WORKDIR /build/standalone',
         'ENV COMPOSER_MAX_PARALLEL_HTTP=2 COMPOSER_MAX_PARALLEL_PROCESSES=2\n\nWORKDIR /build/standalone'),
        ('RUN composer install \\\n', 'RUN composer install --no-plugins --no-autoloader --prefer-dist \\\n'),
        ('RUN composer dump-autoload \\\n', 'RUN --network=none composer dump-autoload --no-plugins \\\n'),
        ('RUN php -r \'require "/app/vendor/autoload.php";\'',
         'RUN --network=none php -r \'require "/app/vendor/autoload.php";\''),
        ('docker-php-ext-install mbstring', 'docker-php-ext-install -j2 mbstring'),
        ('    && mkdir -p /data /app/bootstrap/cache /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs',
         '    && mkdir -p /data /app/bootstrap/cache \\\n        /app/storage/framework/cache/data /app/storage/framework/sessions \\\n        /app/storage/framework/views /app/storage/logs'),
        ('\nVOLUME ["/data"]', '\nUSER 82:82\n\nVOLUME ["/data"]'),
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


@dataclass(frozen=True)
class ArchiveBudget:
    archive_bytes: int
    member_bytes: int
    content_bytes: int
    members: int

    def validate(self):
        require(all(positive(value) for value in (self.archive_bytes, self.member_bytes,
                    self.content_bytes, self.members)), 'Assembly archive budget UNBOUND')
        require(self.member_bytes <= self.content_bytes <= self.archive_bytes <= 1024 ** 3
                and self.members <= 32768, 'Assembly archive budget exceeds source ceiling')


def closed_path(name):
    require(isinstance(name, str) and 0 < len(name.encode()) <= 4096
            and name == unicodedata.normalize('NFC', name)
            and not any(character in name for character in ('\\', '\0', '\r', '\n', ':')),
            'Assembly archive path encoding')
    parts = name.split('/')
    require(not any(part in ('', '.', '..') or part.startswith('.wh.') for part in parts),
            'Assembly archive absolute/parent/whiteout path')
    require(parts[0] in ('app', 'usr', 'etc', 'opt', 'lib', 'lib64', 'bin', 'sbin', 'var', 'data'),
            'Assembly archive root outside closed output scope')
    return name.casefold()


def closed_manifest(value, budget):
    require(isinstance(value, dict) and 0 < len(value) <= budget.members, 'Assembly manifest shape/count')
    result, folded, prefixes = {}, set(), {}
    for name, supplied in value.items():
        key = closed_path(name)
        require(key not in folded, 'Assembly manifest case collision')
        folded.add(key)
        register_prefixes(name, prefixes)
        result[name] = member_manifest(supplied, budget)
    require(len(canonical(result)) <= 16 * 1024 * 1024, 'Assembly manifest byte bound')
    for name in result:
        parts = name.split('/')
        require(all(result.get('/'.join(parts[:length]), {'kind': DIRECTORY})['kind'] == DIRECTORY
                    for length in range(1, len(parts))), 'Assembly manifest file is an ancestor')
    return result


def member_manifest(supplied, budget):
    exact(supplied, ('kind', 'mode', 'uid', 'gid', 'size', 'sha256'), 'Assembly manifest member shape')
    item = dict(supplied)
    require(all(type(item[field]) is int for field in ('mode', 'uid', 'gid', 'size'))
            and (item['uid'], item['gid']) in ((0, 0), (82, 82)), 'Assembly manifest owner/type')
    directory = item['kind'] == DIRECTORY
    require(item['kind'] in ('file', DIRECTORY) and item['mode'] in (0o600, 0o644, 0o700, 0o755)
            and 0 <= item['size'] <= budget.member_bytes, 'Assembly manifest kind/mode/size')
    require((item['size'] == 0 and item['sha256'] is None) if directory else sha(item['sha256']),
            'Assembly manifest content identity')
    return item


def register_prefixes(name, prefixes):
    parts = name.split('/')
    for length in range(1, len(parts) + 1):
        prefix = '/'.join(parts[:length])
        key = prefix.casefold()
        require(prefixes.get(key, prefix) == prefix, 'Assembly manifest parent case collision')
        prefixes[key] = prefix


def closed_member(member, expected, archive):
    directory = member.type == tarfile.DIRTYPE
    require(member.type in (tarfile.REGTYPE, tarfile.AREGTYPE, tarfile.DIRTYPE)
            and getattr(member, 'sparse', None) is None
            and set(member.pax_headers) <= {'path'}
            and member.pax_headers.get('path', member.name) == member.name
            and member.mtime == 0 and member.uname == '' and member.gname == '' and member.linkname == '',
            'Assembly archive link/device/sparse/opaque metadata refused')
    actual = {'kind': DIRECTORY if directory else 'file', 'mode': member.mode,
              'uid': member.uid, 'gid': member.gid, 'size': member.size, 'sha256': None}
    require(all(actual[key] == expected[key] for key in ('kind', 'mode', 'uid', 'gid', 'size')),
            'Assembly member header metadata mismatch')
    content = b''
    if not directory:
        require(member.size == expected['size'], 'Assembly member declared size mismatch')
        content = archive.extractfile(member).read(member.size + 1)
        require(len(content) == member.size, 'Assembly member content length mismatch')
        actual['sha256'] = digest(content)
    require(actual == expected, 'Assembly archive content/metadata differs from independent manifest')
    return content


def sealed_archive(raw, expected, budget):
    """Declassify only immutable bytes; descriptors/mutable streams are refused.

    A future caller must capture a bounded stream before calling this function.
    The returned canonical archive bytes are the only permitted assembly input;
    no later file-path read can replace the verified input.
    """
    budget.validate()
    require(type(raw) is bytes and 0 < len(raw) <= budget.archive_bytes, 'Assembly archive immutable byte bound')
    expected = closed_manifest(expected, budget)
    seen, folded, content_bytes = set(), set(), 0
    output, source = io.BytesIO(), io.BytesIO(raw)
    try:
        with tarfile.open(fileobj=source, mode='r:') as archive, tarfile.open(fileobj=output, mode='w:') as sealed:
            for member in archive:
                key = closed_path(member.name)
                require(member.name not in seen and key not in folded and member.name in expected,
                        'Assembly archive duplicate/case collision/extra member')
                require(len(seen) < budget.members, 'Assembly archive count bound')
                seen.add(member.name)
                folded.add(key)
                content_bytes += expected[member.name]['size']
                require(content_bytes <= budget.content_bytes, 'Assembly aggregate content byte bound')
                content = closed_member(member, expected[member.name], archive)
                info = tarfile.TarInfo(member.name)
                info.mode, info.uid, info.gid = member.mode, member.uid, member.gid
                info.type, info.size = member.type, len(content)
                sealed.addfile(info, io.BytesIO(content))
        require(set(expected) == seen, 'Assembly archive member absent')
        require(not any(raw[source.tell():]), 'Assembly archive trailing payload')
    except (tarfile.TarError, OSError) as error:
        raise Refusal('Assembly archive refused') from error
    result = output.getvalue()
    require(len(result) <= budget.archive_bytes, 'Sealed assembly archive byte bound')
    return {'archive': result, 'archive_sha256': digest(result), 'manifest_sha256': digest(canonical(expected)),
            'content_bytes': content_bytes, 'members': len(seen)}
