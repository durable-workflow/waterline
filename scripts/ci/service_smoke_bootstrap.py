"""Unwired ordinary native/published/cleanup invocation from verified bytes.

The existing workflow's literal first-stage guard supplies the retained root
descriptor and this exact source. There is no CLI, dispatch flag or import-time
effect. The literal subjects below are refreshed only while authoring reviewed
source, never from candidate-supplied expected values during a run.
"""

from __future__ import annotations

import base64
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import time
from types import ModuleType


LOADING_SHA256 = "8da52d8e882902b35d4083b78139c27dc29803c585d53d00713a02d488e4ca4f"
REVIEWED = {
    "oci_release": "d4944b76217dc305f7600c6a6592327e6d298b23368889d6803daad20f65d66d",
    "published_provenance": "ed3f8e1f21b701ff45863ec3c1e5f08ed007ce7961b1a0c1a22bd58a9a96cf37",
    "service_smoke_browser": "bf0ff569c7570cb2390633dd613a95b3fc57a034552cd8c43f7be891b2bb8f4b",
    "service_smoke_browser_runner": "cfe6d9eb9b0300668a1f7420776d8b13f5c6a82f103220dcb3dcc151ba58c965",
    "service_smoke_browser_source": "d300b6b7efa28c340a684905fbf640900fa90efa43b6ad1773e0ce41d3ed54d5",
    "service_smoke_cleanup": "e8efefc70b6a2ebb899bf52a9fc7f738f7c38934e7309286f9cf709fa0d057f6",
    "service_smoke_entry": "1bfe19c1a099bee4ca63dae3b4b8b380ebeb17ea5199093d7f2efecc8dd38710",
    "service_smoke_fixture": "01e673516ad5d96e100b3cda37fd39063dd60c38f49f87faadcfd5abef07c4a2",
    "service_smoke_http": "1fb1acf0399e038ea98189d30b313c03c7079d191e03762ed95f8b1215718104",
    "service_smoke_io": "c0f9f5d2810b278b61a226223742a3f6de22c42ea70b7c64662fa57e98e95a29",
    "service_smoke_native": "b687f239d6a7465d2b2b356f3997347bc7d4a299a672b6d943c6cce42138c939",
    "service_smoke_network": "dd0b17cd7f457182b9bfa605f37d332763b2d53c8f266f0c2a298e8423a2f7cf",
    "service_smoke_observation": "14c8eb1a134a2c78f9734befa3ba1f2cdcdf151073422f489fbcc85e917b1d97",
    "service_smoke_published": "7fe20cc540ee22cb787a0af419268437b6b970057392a563c1d487e2e20c9098",
    "service_smoke_published_entry": "412a76aeb2f008a3c7343d605c9e03cc3f26c22e0abb8b815d5d7973f3ae6ff3",
    "service_smoke_resources": "6a9965bb6546e95cc9eb65d4855c4eb27623b19731b7f53e47318528e4310b77",
    "service_smoke_subjects": "b020667def1e182b82de00b0f6aea464ae4668f39366fdd1b338f7e21d839b14",
    "waterline_release_identity": "4a1f9d251cb67083f59c16ed90360962244aa6e6ee8e382e13205dcc80e2135b"
}
PUBLIC_IMAGE = "docker.io/durableworkflow/waterline"


def require_hosted():
    if os.environ.get("GITHUB_ACTIONS") != "true" or os.environ.get("GITHUB_REPOSITORY") != "durable-workflow/waterline":
        raise RuntimeError("Invocation belongs only to the existing unprivileged Waterline job")
    if not 0 < int(os.environ["GITHUB_RETENTION_DAYS"]) <= 90:
        raise RuntimeError("Existing Actions log retention is outside its selected bound")


def file_identity(value):
    return tuple(getattr(value, key) for key in ("st_dev", "st_ino", "st_mode", "st_uid", "st_gid", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns"))


def read_loading() -> bytes:
    parent = os.dup(__verified_root_fd__)
    try:
        for name in ("scripts", "ci"):
            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
            os.close(parent)
            parent = child
            state = os.fstat(parent)
            if state.st_uid not in {0, os.getuid()} or state.st_mode & 0o022:
                raise RuntimeError("Bootstrap loader ancestor has an unselected write boundary")
        descriptor = os.open("service_smoke_loading.py", os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
        try:
            before = os.fstat(descriptor)
            if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid not in {0, os.getuid()}
                    or before.st_mode & 0o022 or not 0 < before.st_size <= 1024 * 1024):
                raise RuntimeError("Invalid held bootstrap loader")
            raw = os.read(descriptor, 1024 * 1024 + 1)
            if len(raw) != before.st_size or file_identity(os.fstat(descriptor)) != file_identity(before) or hashlib.sha256(raw).hexdigest() != LOADING_SHA256:
                raise RuntimeError("Bootstrap loader substituted before compilation")
            return raw
        finally:
            os.close(descriptor)
    finally:
        os.close(parent)


def engine():
    require_hosted()
    raw = read_loading()
    module = ModuleType("service_smoke_loading")
    module.__file__ = "/__waterline_verified__/scripts/ci/service_smoke_loading.py"
    if module.__name__ in sys.modules:
        raise RuntimeError("Bootstrap loader was imported before verified capture")
    sys.modules[module.__name__] = module
    exec(compile(raw, "<verified ordinary loader>", "exec"), module.__dict__)
    root = module.SourceRoot(Path(__verified_root__))
    try:
        if module.directory_identity(os.fstat(root.descriptor)) != module.directory_identity(os.fstat(__verified_root_fd__)):
            raise RuntimeError("First-stage source root namespace changed")
        captured = module.capture_sources(root, REVIEWED)
        loaded = module.load_sources(captured, REVIEWED)
        return module, loaded, captured, raw
    finally:
        root.close()


def action_outputs(values: dict[str, str]):
    allowed = {"ordinary_code_root", "ordinary_evidence_root"} | {
        prefix + suffix for prefix in ("ordinary_native_", "ordinary_primary_", "ordinary_automatic_")
        for suffix in ("run", "image", "architecture")
    }
    if set(values) - allowed or any(not isinstance(value, str) or "\n" in value or "\r" in value or len(value) > 4096 for value in values.values()):
        raise RuntimeError("Unselected existing-job output context")
    descriptor = os.open(os.environ["GITHUB_OUTPUT"], os.O_WRONLY | os.O_APPEND | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        state = os.fstat(descriptor)
        if not stat.S_ISREG(state.st_mode) or state.st_nlink != 1 or state.st_uid != os.getuid() or state.st_mode & 0o022:
            raise RuntimeError("Job output context is not an owned regular file")
        raw = "".join(key + "=" + value + "\n" for key, value in values.items()).encode()
        if os.write(descriptor, raw) != len(raw):
            raise RuntimeError("Existing-job output context write was incomplete")
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def retain_code(loader, captured, loading_raw):
    parent = loader.SourceRoot(Path(os.environ["RUNNER_TEMP"]))
    try:
        extra = {"service_smoke_loading.py": (loading_raw, LOADING_SHA256),
                 "service_smoke_bootstrap.py": (__verified_source__, hashlib.sha256(__verified_source__).hexdigest())}
        snapshot = loader.store_snapshot(parent, captured, REVIEWED, extra)
        try:
            action_outputs({"ordinary_code_root": str(snapshot.path)})
        finally:
            snapshot.close()
    finally:
        parent.close()


def source_context(loaded, source_name: str) -> tuple[dict, Path]:
    source = os.environ[source_name]
    runner = os.environ["GITHUB_SHA"]
    platform = os.environ["EXPECTED_PLATFORM"]
    if re.fullmatch(r"linux/(?:amd64|arm64)", platform, re.ASCII) is None:
        raise RuntimeError("Unselected actual native platform")
    repository = Path(os.environ["GITHUB_WORKSPACE"])
    git = loaded["service_smoke_subjects"].GitInputs
    retained = []
    try:
        source_input = git(repository, source)
        retained.append(source_input)
        runner_input = git(repository, runner)
        retained.append(runner_input)
        value = {"source": source, "source_tree": source_input.tree, "runner_commit": runner,
                 "runner_tree": runner_input.tree, "architecture": platform.split("/")[1],
                 "run_id": int(os.environ["GITHUB_RUN_ID"]), "run_attempt": int(os.environ["GITHUB_RUN_ATTEMPT"])}
        return value, repository
    finally:
        for value in retained:
            value.close()


def output_root(loader, path: Path):
    os.mkdir(path, 0o700)
    root = loader.SourceRoot(path)
    action_outputs({"ordinary_evidence_root": str(path)})
    return root


def ready(prefix: str):
    def record(value: dict):
        action_outputs({prefix + key: item for key, item in value.items()})
    return record


def emit(report: dict):
    raw = json.dumps(report, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()
    if len(raw) > 4 * 1024 * 1024:
        raise RuntimeError("Ordinary report exceeds its selected bound")
    retention = int(os.environ["GITHUB_RETENTION_DAYS"])
    if not 0 < retention <= 90:
        raise RuntimeError("Existing Actions log retention is outside its selected bound")
    parts = [base64.b64encode(raw[offset:offset + 3072]).decode() for offset in range(0, len(raw), 3072)]
    print(json.dumps({"ordinary_report": {"bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest(),
                                         "parts": len(parts), "retention_days": retention}}), flush=True)
    for index, part in enumerate(parts):
        print(json.dumps({"ordinary_report_part": index, "base64": part}), flush=True)
    if report["status"] != "pass":
        raise RuntimeError("Ordinary acceptance is NONPASS; retained source/run report supplies its reason")


def native():
    started = time.monotonic()
    loader, loaded, captured, loading_raw = engine()
    retain_code(loader, captured, loading_raw)
    selected, repository = source_context(loaded, "SOURCE_COMMIT")
    inputs = loader.SourceRoot(Path(os.environ["RUNNER_TEMP"]) / "native-oci")
    outputs = output_root(loader, Path(os.environ["RUNNER_TEMP"]) / "ordinary-native-evidence")
    archive = None
    try:
        descriptor = inputs.open("image.oci.tar", 1024 * 1024 * 1024)
        try:
            archive = loaded["service_smoke_subjects"].HeldArchive(descriptor)
        finally:
            os.close(descriptor)
        selected["archive_digest"] = archive.digest
        archive.close()
        archive = None
        selected["security_digest"] = "sha256:" + hashlib.sha256(inputs.read("security-summary.json")).hexdigest()
        runner = loaded["service_smoke_subjects"].GitInputs(repository, selected["runner_commit"])
        try:
            selected["workflow_sha256"] = hashlib.sha256(runner.read(".github/workflows/service-image.yml")).hexdigest()
        finally:
            runner.close()
        report = loaded["service_smoke_entry"].run_native(selected, repository=repository, inputs=inputs, outputs=outputs,
                  frozen_document=loader.FrozenDocument, context_ready=ready("ordinary_native_"), started=started,
                  scope_check=__scope_check__)
        if report["status"] == "pass":
            inputs.check()
            loader.write_regular(inputs.descriptor, "sdk.json", json.dumps(report["sdk"], sort_keys=True).encode() + b"\n")
        emit(report)
    finally:
        if archive is not None:
            archive.close()
        inputs.close()
        outputs.close()


def registry_documents(loaded, loader, selected: dict, inputs, started: float):
    docker = loaded["service_smoke_io"].Docker(inputs.path, started=started, scope_check=__scope_check__)
    try:
        def manifest(digest: str) -> bytes:
            result = docker(["buildx", "imagetools", "inspect", "--raw", PUBLIC_IMAGE + "@" + digest])
            if result.returncode != 0 or result.stderr:
                raise RuntimeError("Anonymous current registry manifest read failed")
            return result.stdout
        index = manifest(selected["index_digest"])
        index_value = loaded["service_smoke_published"].document(index, selected["index_digest"])
        platforms = loaded["published_provenance"].check_index(index_value)
        platform = platforms[selected["architecture"]]
        child, attestation = manifest(platform["image"]), manifest(platform["attestation"])
        child_value = loaded["service_smoke_published"].document(child, platform["image"])
        pulled = docker.acquire(PUBLIC_IMAGE + "@" + selected["index_digest"], "linux/" + selected["architecture"])
        if pulled.returncode != 0:
            raise RuntimeError("One bounded anonymous published pull failed; no unchanged retry")
        config = child_value.get("config", {}).get("digest")
        if not isinstance(config, str) or re.fullmatch(r"sha256:[a-f\d]{64}", config, re.ASCII) is None:
            raise RuntimeError("Published child config selector is invalid")
        inspected = docker(["image", "inspect", config])
        if inspected.returncode != 0 or inspected.stderr:
            raise RuntimeError("Selected public image inspection failed")
        for name, raw in {"index.json": index, "child.json": child, "attestation.json": attestation,
                          "engine-image.json": inspected.stdout}.items():
            loader.write_regular(inputs.descriptor, name, raw)
    finally:
        docker.close()


def published(producer_gate_succeeded: bool):
    if producer_gate_succeeded is not True:
        raise RuntimeError("Existing signed producer job must finish before this published invocation")
    started = time.monotonic()
    loader, loaded, captured, loading_raw = engine()
    retain_code(loader, captured, loading_raw)
    selected, repository = source_context(loaded, "EXPECTED_SOURCE_COMMIT")
    selected.update({"index_digest": os.environ["IMAGE_DIGEST"], "publisher_commit": os.environ["PUBLISHER_COMMIT"],
                     "verification_run_id": selected["run_id"], "verification_run_attempt": selected["run_attempt"]})
    loaded["service_smoke_published_entry"].selected_context(selected)
    parent = Path(os.environ["RUNNER_TEMP"])
    os.mkdir(parent / "ordinary-published-inputs", 0o700)
    inputs = loader.SourceRoot(parent / "ordinary-published-inputs")
    dependencies = loader.SourceRoot(parent / "native-budget")
    outputs = output_root(loader, dependencies.path / "request-budget-evidence")
    node_root = None
    descriptor = -1
    try:
        registry_documents(loaded, loader, selected, inputs, started)
        node_path = shutil.which("node")
        if node_path is None:
            raise RuntimeError("Existing pinned Node tool is unavailable")
        node_root = loader.SourceRoot(Path(node_path).parent)
        descriptor = node_root.open(Path(node_path).name, 128 * 1024 * 1024)
        with os.fdopen(os.dup(descriptor), "rb") as node_file:
            node_hash = hashlib.file_digest(node_file, "sha256").hexdigest()
        report = loaded["service_smoke_published_entry"].run_published(selected, repository=repository, inputs=inputs,
                 outputs=outputs, dependencies=dependencies, node_descriptor=descriptor, node_sha256=node_hash,
                 root_type=loader.SourceRoot, context_ready=lambda kind, value: ready("ordinary_" + kind + "_")(value),
                 producer_gate_succeeded=True, started=started, scope_check=__scope_check__)
        emit(report)
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        if node_root is not None:
            node_root.close()
        for root in (outputs, dependencies, inputs):
            root.close()


def cleanup(contexts: list[dict]):
    loader, loaded, _captured, _raw = engine()
    parent = loader.SourceRoot(Path(os.environ["RUNNER_TEMP"]))
    docker = loaded["service_smoke_io"].Docker(parent.path)
    try:
        results = [loaded["service_smoke_cleanup"].recover(docker.cleanup, value) for value in contexts]
        emit({"schema": "waterline.ordinary-independent-cleanup/v1", "results": results,
              "status": "pass" if results and all(value["status"] == "pass" for value in results) else "nonpass"})
    finally:
        docker.close()
        parent.close()
