"""Independent provider bytes + fresh same-daemon observations, never a fetch loop."""
import io
import stat
import zipfile

from contract import (MAX_ARCHIVE, MAX_REPORT, REPOSITORY, WORKFLOW, bounded_json,
                      canonical, digest, positive, require, require_complete_spec,
                      same_cell, unbound_spec)
from producer import produce


def provider(run, artifact, context):
    context.validate()
    require(positive(run.get('id')) and positive(run.get('run_attempt'))
            and run['id'] == context.run_id and run['run_attempt'] == context.attempt
            and run.get('head_sha') == context.preparation_commit and run.get('path') == WORKFLOW
            and (run.get('repository') or {}).get('full_name') == REPOSITORY,
            'Provider run/source/attempt mismatch')
    # Binding occurs within this running cell, not after a completed run whose
    # ephemeral daemon is gone. Artifact success is not whole-run success.
    require(run.get('status') == 'in_progress' and run.get('conclusion') is None
            and run.get('event') == 'workflow_dispatch', 'Provider cell no longer live or is not admitted event')
    require(positive(artifact.get('id')) and artifact.get('name') == context.artifact_name()
            and artifact.get('expired') is False
            and (artifact.get('workflow_run') or {}).get('id') == context.run_id
            and (artifact.get('workflow_run') or {}).get('head_sha') == context.preparation_commit,
            'Provider artifact binding mismatch')
    size = artifact.get('size_in_bytes')
    require(type(size) is int and 0 < size <= MAX_ARCHIVE, 'Provider artifact byte bound')


def report_bytes(archive, artifact):
    require(isinstance(archive, bytes) and 0 < len(archive) <= MAX_ARCHIVE, 'Downloaded artifact byte bound')
    require(artifact.get('digest') == 'sha256:' + digest(archive), 'Downloaded provider digest mismatch')
    try:
        with zipfile.ZipFile(io.BytesIO(archive)) as value:
            entries = value.infolist()
            require(len(entries) == 1, 'Artifact has extra or duplicate members')
            item = entries[0]
            mode = item.external_attr >> 16
            require(item.filename == 'preparation-report.json' and not item.is_dir()
                    and not item.flag_bits & 1 and item.file_size <= MAX_REPORT
                    and stat.S_IFMT(mode) in (0, stat.S_IFREG), 'Artifact member type/path/size refused')
            with value.open(item) as stream:
                raw = stream.read(MAX_REPORT + 1)
            require(len(raw) == item.file_size and len(raw) <= MAX_REPORT, 'Artifact extraction bound')
            return raw
    except (zipfile.BadZipFile, RuntimeError, OSError) as error:
        raise ValueError('Provider archive refused') from error


def bind(run, artifact, archive, prepared_context, fresh_host, expected, tool_lock, evidence, admission, authority):
    """The provider digest alone cannot endorse image/config or source facts."""
    provider(run, artifact, prepared_context)
    same_cell(prepared_context, fresh_host.context)
    downloaded = bounded_json(report_bytes(archive, artifact))
    independently_recaptured = produce(fresh_host, expected, tool_lock, evidence, admission, authority)
    require(downloaded == independently_recaptured, 'Provider report differs from independent live host facts')
    receipt = {key: independently_recaptured[key] for key in (
        'source', 'lock', 'graph', 'installed_json_sha256', 'ca_sha256', 'tool_config_sha256',
        'scripts', 'plugins', 'request_concurrency', 'effective_trust_verified')}
    receipt.update({'provider_run_id': run['id'], 'provider_artifact_id': artifact['id'],
                    'provider_api_digest': artifact['digest'][7:], 'provider_run_attempt': prepared_context.attempt,
                    'preparation': independently_recaptured['preparation'],
                    'context': prepared_context.report_context(),
                    'images': {key: independently_recaptured[key] for key in ('app', 'tool')}})
    raw_receipt = canonical(receipt)
    spec = unbound_spec()
    spec.update({'platform': independently_recaptured['app']['platform'],
                 'image_id': independently_recaptured['app']['id'],
                 'image_config_sha256': independently_recaptured['app']['config_sha256'],
                 'browser_image_id': independently_recaptured['tool']['id'],
                 'browser_config_sha256': independently_recaptured['tool']['config_sha256'],
                 'vendor_preparation_receipt_sha256': digest(raw_receipt)})
    require_complete_spec(spec)
    return raw_receipt, spec
