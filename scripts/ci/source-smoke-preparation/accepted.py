"""Read only the already accepted collector helper from immutable Git objects."""
import subprocess
import types
import io
import re
import tarfile
import time
import tempfile
from pathlib import Path

import contract
from contract import PACKET_COMMIT, PACKET_TREE, bounded_json, digest, image_id, require, source_expectation
from guardian import LABELS, binding, planned

COLLECTOR_SHA256 = 'dcc263b37fb9e8bc2298ac323e9bb0dd9d2cf9686fd56b77c64921652a92f00d'
OCI_COMMIT = '2cc1f3915435f909b1624c1ceda860c027ade4d5'
OCI_TREE = '237645fb50b9e8b34ef21b1045798edb2b72497d'
OCI_SHA256 = 'e75e819b1d1a6407f8732f81becc7d879453063225a7085f52ab24200e24c4d6'
MAX_FILESYSTEM = 128 * 1024 * 1024
TEMPORARY = '/waterline-preparation-tmp'
TMPFS = {TEMPORARY: 'rw,size=16m,mode=0700,uid=82,gid=82', '/data': 'rw,size=64m,mode=0700,uid=82,gid=82'}
CONTAINER = 'container'
VERIFY_POLICY = {'ReadonlyRootfs': True, 'Privileged': False, 'NanoCpus': 2_000_000_000,
                 'Memory': 3 * 1024 ** 3, 'MemorySwap': 3 * 1024 ** 3, 'PidsLimit': 256,
                 'CapDrop': ['ALL'], 'SecurityOpt': ['no-new-privileges'],
                 'RestartPolicy': {'Name': 'no', 'MaximumRetryCount': 0}, 'IpcMode': 'private', 'Tmpfs': TMPFS}


def accepted_module(repository, commit, tree, path, module_name, expected_sha256):
    def git(*args):
        result = subprocess.run(['git', '-C', str(repository), *args], capture_output=True, timeout=5, check=True)
        require(len(result.stdout) <= 262144, 'Accepted Git object bound')
        return result.stdout
    require(git('rev-parse', commit + '^{tree}').strip().decode() == tree, 'Accepted helper tree changed')
    name = commit + ':' + path
    require(int(git('cat-file', '-s', name)) <= 262144, 'Accepted evidence object bound')
    raw = git('show', name)
    require(digest(raw) == expected_sha256, 'Accepted helper bytes changed')
    module = types.ModuleType(module_name)
    module.__file__ = '/reviewed-packet/' + path
    exec(compile(raw, module.__file__, 'exec'), module.__dict__)
    return module


def accepted_evidence(repository):
    return accepted_module(repository, PACKET_COMMIT, PACKET_TREE,
                           'scripts/ci/source-smoke-evidence/evidence.py', 'accepted_source_evidence',
                           '00efcf2abe33db557c5a00e35f4f3944624768ff4d5c52665d1ded65c9b306b6')


def accepted_oci(repository):
    return accepted_module(repository, OCI_COMMIT, OCI_TREE, 'scripts/ci/oci_release.py',
                           'accepted_native_oci', OCI_SHA256)


def source_context_files(repository, reader):
    evidence = accepted_evidence(repository)
    expected = evidence.expected_from_git(repository)
    source_expectation(expected)
    verifiers = ('docker/verify-ca-trust.php', 'docker/verify-brotli.php', 'docker/verify-curl.php')
    raw_archive = reader.git_small(repository, 'archive', '--format=tar', contract.SOURCE, limit=evidence.MAX_TOTAL)
    files, projected = {}, {}
    with tarfile.open(fileobj=io.BytesIO(raw_archive), mode='r:') as archive:
        for member in archive:
            name = member.name
            targets = evidence.docker_copy_targets(name)
            if member.isdir() or not (targets or name in (*verifiers, '.dockerignore')):
                continue
            raw = evidence.archived_content(archive, member)
            # Match the admitted image-leaf modes, independent of Git tar's
            # default group-write bits. Source bytes remain the exact M1 blobs.
            mode = 0o755 if name in ('standalone/artisan', 'standalone/entrypoint.sh') else member.mode & 0o777
            files[name] = (raw, mode)
            for target in targets:
                # The fixed M1 Dockerfile applies chmod +x to these two leaves.
                final_mode = 0o755 if target in ('artisan', 'entrypoint.sh') else mode
                projected[target] = {'mode': final_mode, 'size': len(raw), 'sha256': digest(raw)}
    require(projected == expected['files'] and all(name in files for name in verifiers),
            'M1 context source mapping changed')
    return files


def closed_oci_tar(raw):
    offset = 0
    with tarfile.open(fileobj=io.BytesIO(raw), mode='r:') as archive:
        for count, member in enumerate(archive, 1):
            require(count <= 2048 and not member.pax_headers and not member.sparse
                    and member.offset == offset and member.offset_data == offset + 512,
                    'Opaque or oversized OCI tar metadata')
            offset = member.offset_data + ((member.size + 511) // 512) * 512
    require(len(raw) % 512 == 0 and len(raw) - offset >= 1024 and not any(memoryview(raw)[offset:]),
            'Opaque or missing OCI tar end markers')


def oci_children(oci, root, descriptor):
    allowed = {'mediaType', 'digest', 'size', 'annotations', 'platform'}
    require(type(descriptor) is dict and set(descriptor) <= allowed, 'Unbound OCI descriptor fields')
    media = descriptor.get('mediaType')
    path = oci.blob(root, descriptor)
    if media == oci.INDEX:
        return oci.read_json(path)['manifests']
    if media == oci.MANIFEST:
        value = oci.read_json(path)
        children = [value['config'], *value['layers']]
        if 'subject' in value:
            children.append(value['subject'])
        return children
    require(media in (oci.CONFIG, oci.EMPTY_CONFIG, oci.IN_TOTO,
                      'application/vnd.oci.image.layer.v1.tar+gzip',
                      'application/vnd.oci.image.layer.v1.tar+zstd',
                      'application/vnd.oci.image.layer.v1.tar'), 'Unbound OCI blob type')
    return []


def closed_oci_blobs(oci, root):
    pending = list(oci.read_json(root / 'index.json')['manifests'])
    seen = set()
    while pending:
        require(len(pending) + len(seen) <= 2048, 'OCI descriptor count bound')
        descriptor = pending.pop()
        children = oci_children(oci, root, descriptor)
        identifier = descriptor['digest'].removeprefix('sha256:')
        if identifier in seen:
            continue
        seen.add(identifier)
        pending.extend(children)
    actual = {path.name for path in (root / 'blobs/sha256').iterdir()}
    require(seen == actual, 'Unreferenced OCI payload')
    return sorted(seen)


def native_build_arguments(oci, root, attestation, expected):
    contract.exact(expected, (field for field, _argument in contract.HOSTED_BUILD_ARGUMENTS),
                   'Independent build input shape')
    require(all(contract.sha(value) for value in expected.values()), 'Independent build digests missing')
    manifest = oci.read_json(oci.blob(root, attestation))
    layers = [layer for layer in manifest['layers']
              if (layer.get('annotations') or {}).get(oci.PREDICATE_TYPE) == oci.SLSA_PROVENANCE]
    require(len(layers) == 1, 'Build provenance missing')
    statement = oci.document(oci.blob(root, layers[0]).read_bytes(), oci.MAX_ATTESTATION)
    require(bool(statement.get('subject')), 'Build provenance has no image subject')
    request = statement['predicate']
    for key in ('buildDefinition', 'externalParameters', 'request', 'args'):
        request = request.get(key) if type(request) is dict else None
    require(type(request) is dict, 'Build provenance arguments missing')
    for key, argument in contract.HOSTED_BUILD_ARGUMENTS:
        require(request.get('build-arg:' + argument) == expected[key], 'Build input digest substitution')


def native_archive_subject(repository, raw, architecture, bindings):
    """Inert bytes only; never fetch, import or execute an image.

    The strict tar profile is synthetic/unqualified until a separately admitted
    native export supplies actual bytes. A config digest is not a daemon ID.
    """
    oci = accepted_oci(repository)
    require(type(raw) is bytes and 0 < len(raw) <= oci.MAX_ARCHIVE, 'Native archive byte bound')
    require(architecture in ('amd64', 'arm64'), 'Native platform missing')
    try:
        closed_oci_tar(raw)
        with tempfile.TemporaryDirectory(prefix='waterline-native-source-') as temporary:
            archive = Path(temporary) / 'subject.tar'
            archive.write_bytes(raw)
            root = Path(temporary) / 'layout'
            oci.unpack_oci(archive, root)
            image, config_digest, attestation = oci.inspect_oci(root, architecture, contract.SOURCE)
            native_build_arguments(oci, root, attestation, bindings)
            blobs = closed_oci_blobs(oci, root)
    except (OSError, ValueError, TypeError, KeyError, AttributeError, tarfile.TarError) as error:
        raise contract.Refusal('Unqualified native archive refused') from error
    return {'size': len(raw), 'sha256': digest(raw), 'platform': 'linux/' + architecture,
            'manifest_digest': image['digest'], 'config_digest': config_digest,
            'attestation_digest': attestation['digest'], 'blobs': blobs,
            'daemon_image_id': None}


def archive_content(archive, member, limit):
    require(member.isfile() and 0 <= member.size <= limit, 'Application archive leaf type/bound')
    content = archive.extractfile(member).read(member.size + 1)
    require(len(content) == member.size, 'Application archive leaf length mismatch')
    return content


def archive_path(member, seen):
    name = member.name.removeprefix('./')
    require(not name.startswith('/') and '..' not in name.split('/')
            and len(name) <= 4096 and len(seen) < 32768, 'Application archive path/count bound')
    require(name not in seen, 'Duplicate application archive member')
    seen.add(name)
    return name


def application_archive(raw, expected):
    """Read daemon-produced filesystem bytes, without executing candidate PHP."""
    source_expectation(expected)
    require(isinstance(raw, bytes) and len(raw) <= MAX_FILESYSTEM, 'Application filesystem byte bound')
    files, installed, seen, links = {}, None, set(), set()
    with tarfile.open(fileobj=io.BytesIO(raw), mode='r:') as archive:
        for member in archive:
            name = archive_path(member, seen)
            if member.issym() or member.islnk():
                links.add(name)
            if member.isdir() or name in ('.', ''):
                continue
            if name == 'vendor/composer/installed.json':
                installed = archive_content(archive, member, contract.MAX_REPORT)
            elif not name.startswith(('vendor/', 'storage/', 'bootstrap/cache/')):
                require(name in expected['files'], 'Extra immutable application leaf')
                content = archive_content(archive, member, 16 * 1024 * 1024)
                files[name] = {'mode': member.mode & 0o777, 'size': member.size, 'sha256': digest(content)}
    require(files == expected['files'] and installed is not None, 'Independent application filesystem mismatch')
    for name in (*files, 'vendor/composer/installed.json'):
        parts = name.split('/')
        require(not any('/'.join(parts[:length]) in links for length in range(1, len(parts) + 1)),
                'Application filesystem link ancestor')
    return files, installed


def offline_container(value, plan, identifier, expected_image):
    """Inspect effective settings before any candidate code and after it."""
    require(binding(value, plan).get('Id') == identifier and value.get('Image') == expected_image,
            'Verification container immutable identity mismatch')
    state, host, config = value.get('State', {}), value.get('HostConfig', {}), value.get('Config', {})
    require(state.get('Running') is True and state.get('OOMKilled') is False
            and config.get('User') == '82:82', 'Verification process identity/state mismatch')
    networks = value.get('NetworkSettings', {}).get('Networks') or {}
    require(host.get('NetworkMode') == 'none' and set(networks) <= {'none'}
            and all(not details.get(key) for details in networks.values() for key in
                    ('IPAddress', 'Gateway', 'GlobalIPv6Address', 'IPv6Gateway')),
            'Verification networking is not absent')
    require(all(type(host.get(key)) is type(expected) and host[key] == expected
                for key, expected in VERIFY_POLICY.items()),
            'Verification resource/security policy mismatch')
    require(all(host.get(key) in (None, []) for key in ('Devices', 'Binds', 'VolumesFrom', 'CapAdd'))
            and host.get('PortBindings') in (None, {}) and host.get('PidMode') in ('', 'private'),
            'Verification host authority detected')
    require(all(mount.get('Type') == 'tmpfs' and mount.get('Destination') in TMPFS
                and mount.get('Source') in ('', None) for mount in value.get('Mounts', [])),
            'Verification mount authority detected')


class ApplicationReader:
    """Held live application reader; never builds, allocates or starts roles."""
    def __init__(self, evidence, context, authority, deadline):
        authority.identity(context)
        self.evidence, self.context, self.authority, self.deadline = evidence, context, authority, deadline
        self.commands = []

    def docker(self, *args, limit=contract.MAX_REPORT, input_bytes=None):
        require(contract.EXECUTION_ENABLED, 'HELD: no application host read admitted')
        require(time.monotonic() < self.deadline, 'Application reader deadline expired')
        self.authority.identity(self.context)
        raw, receipt = self.evidence.command(['docker', 'info', '--format', '{{.ID}}'],
            min(self.deadline, time.monotonic() + 3), stdout_limit=256)
        self.commands.append(receipt)
        require(raw.strip().decode() == self.context.daemon_id, 'Application reader daemon changed')
        raw, receipt = self.evidence.command(['docker', *args], min(self.deadline, time.monotonic() + 30),
            stdout_limit=limit, input_bytes=input_bytes)
        self.commands.append(receipt)
        return raw

    def inspect(self, kind, identifier):
        values = bounded_json(self.docker(kind, 'inspect', identifier))
        require(isinstance(values, list) and len(values) == 1, 'Application root inspect shape')
        return values[0]

    def capture(self, identifier, plan, expected_image, expected_config, expected, collector):
        require(contract.EXECUTION_ENABLED, 'HELD: no application capture admitted')
        planned(plan)
        require(plan['kind'] == CONTAINER and plan['label'] == LABELS[0]
                and plan['owner'] == self.context.nonce and isinstance(identifier, str)
                and re.fullmatch(r'[a-f0-9]{64}', identifier), 'Application verification ownership missing')
        require(image_id(expected_image) and digest(collector) == COLLECTOR_SHA256,
                'Independent image/collector authority missing')
        before = self.inspect(CONTAINER, identifier)
        offline_container(before, plan, identifier, expected_image)
        image = self.inspect('image', expected_image)
        require(image.get('Id') == expected_image and image.get('Config') == expected_config,
                'Application image/config readback mismatch')
        config = before.get('Config', {})
        require(config.get('Env') == expected_config.get('Env') and config.get('Entrypoint') == ['sleep']
                and config.get('Cmd') == ['1200'] and config.get('WorkingDir') == '/app',
                'Verification process/environment authority mismatch')
        files, installed = application_archive(self.docker('cp', identifier + ':/app/.', '-', limit=MAX_FILESYSTEM), expected)
        metadata = self.evidence.bounded_json(installed, limit=contract.MAX_REPORT)
        require(metadata.get('dev') is False and metadata.get('dev-package-names') == []
                and self.evidence.graph(metadata.get('packages')) == expected['graph'],
                'Independent installed metadata mismatch before autoload')
        offline_container(self.inspect(CONTAINER, identifier), plan, identifier, expected_image)
        observed = bounded_json(self.docker('exec', '-i', '--user', '82:82', identifier,
                                           'php', '-d', 'sys_temp_dir=' + TEMPORARY,
                                           '/dev/stdin', 'identity', input_bytes=collector))
        self.evidence.identity({**expected, 'installed_json_sha256': digest(installed)}, observed)
        require(observed.get('files') == files, 'Candidate observation differs from daemon filesystem bytes')
        after = self.inspect(CONTAINER, identifier)
        offline_container(after, plan, identifier, expected_image)
        require(after.get('Config') == config, 'Verification process configuration changed')
        require(self.inspect('image', expected_image) == image, 'Application image changed during capture')
        return {'image': image, 'installed_raw': installed, 'files': files, 'classes': observed['classes'],
                'php': observed['php'], 'int_size': observed['int_size'], 'network_removed_before_autoload': True,
                'commands': list(self.commands)}
