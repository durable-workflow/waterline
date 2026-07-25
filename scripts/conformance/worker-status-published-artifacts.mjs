import crypto from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';

import { validatedPublishedHost, waterlinePublishedTopology } from './worker-status-network.mjs';
import {
  boundedDiagnostic,
  createInterruptionMonitor,
  runWithCleanup,
  waitForHttpReadiness,
} from './worker-status-runner-lifecycle.mjs';
import { verifySharedWaterlineIsolation } from './worker-status-shared-isolation.mjs';
import {
  workerIdPrefixBindingEvidence,
  sharedServerReceiptFailures,
  workerStatusWorkerIds,
  workerStatusWorkflowIds,
  workflowIdPrefixBindingEvidence,
} from './worker-status-shared-topology.mjs';
import { isExact2xPrerelease, isExactSemverRelease } from './worker-status-version.mjs';

const RESULT_DIR = requiredEnv('RESULT_DIR');
const STARTED_AT = now();
const RUN_ID = `${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
const SUFFIX = RUN_ID.replace(/[^a-zA-Z0-9]/g, '').slice(-12).toLowerCase();
const SERVER_VERSION = env('DW_SERVER_VERSION');
const CLI_VERSION = normalizeVersion(env('DW_CLI_VERSION'));
const SDK_PHP_VERSION = normalizeVersion(env('DW_PHP_SDK_VERSION'));
const WORKFLOW_VERSION = normalizeVersion(env('DW_WORKFLOW_PHP_VERSION'));
const WATERLINE_VERSION = normalizeVersion(env('DW_WATERLINE_VERSION'));
const SERVER_IMAGE = env('DW_SERVER_IMAGE') || `durableworkflow/server:${SERVER_VERSION}`;
const SHARED_SERVER_STATE_FILE = env('DW_WATERLINE_WORKER_STATUS_SHARED_SERVER_STATE');
const USE_SHARED_SERVER = SHARED_SERVER_STATE_FILE !== '';
const COMPOSER_IMAGE = env('DW_WATERLINE_WORKER_STATUS_COMPOSER_IMAGE') || 'composer:2';
const TOKEN = env('DW_WATERLINE_WORKER_STATUS_AUTH_TOKEN') || 'dev-token';
const NAMESPACE = env('DW_WATERLINE_WORKER_STATUS_NAMESPACE') || 'waterline-worker-status';
const HEARTBEAT_SECONDS = positiveInt(env('DW_WATERLINE_WORKER_STATUS_HEARTBEAT_SECONDS'), 2);
const STALE_SECONDS = positiveInt(env('DW_WATERLINE_WORKER_STATUS_STALE_SECONDS'), 7);
const READINESS_ATTEMPT_SECONDS = positiveInt(env('DW_WATERLINE_WORKER_STATUS_READINESS_ATTEMPT_SECONDS'), 5);
const READINESS_DEADLINE_SECONDS = positiveInt(env('DW_WATERLINE_WORKER_STATUS_READINESS_DEADLINE_SECONDS'), 120);
const KEEP_RUN_ROOT = truthy(env('DW_WATERLINE_WORKER_STATUS_KEEP_RUN_ROOT'));
let WATERLINE_HOST = env('DW_WATERLINE_HOST');
const HOST_UID = typeof process.getuid === 'function' ? process.getuid() : null;
const HOST_GID = typeof process.getgid === 'function' ? process.getgid() : null;
const CONTAINER_USER = `${HOST_UID}:${HOST_GID}`;
const RUN_ROOT = fs.mkdtempSync(path.join(RESULT_DIR, 'waterline-worker-status-run.'));
const APP_DIR = path.join(RUN_ROOT, 'app');
const CLI_DIR = path.join(RUN_ROOT, 'cli');
const COMPOSER_HOME_DIR = path.join(RUN_ROOT, 'composer-home');
const COMPOSE_FILE = path.join(RUN_ROOT, 'docker-compose.yml');
const PROJECT = `dw-waterline-status-${SUFFIX}`;
let NETWORK = `${PROJECT}_default`;
const WATERLINE_CONTAINER = `dw-waterline-status-ui-${SUFFIX}`;
const TASK_QUEUE = `waterline-status-${SUFFIX}`;
const WORKER_IDS = workerStatusWorkerIds(RUN_ID);
const WORKFLOW_IDS = workerStatusWorkflowIds(RUN_ID);
const EVIDENCE_PATH = path.join(RESULT_DIR, 'waterline-worker-status-evidence.json');
const RESULT_PATH = path.join(RESULT_DIR, 'waterline-worker-status-result.json');
const ARTIFACT_VERSIONS = {
  server: SERVER_VERSION,
  cli: CLI_VERSION,
  'sdk-php': SDK_PHP_VERSION,
  workflow: WORKFLOW_VERSION,
  waterline: WATERLINE_VERSION,
};
const ARTIFACT_SOURCES = {
  server: `docker://${SERVER_IMAGE}`,
  cli: 'github_release',
  'sdk-php': `packagist://durable-workflow/sdk@${SDK_PHP_VERSION}`,
  workflow: `packagist://durable-workflow/workflow@${WORKFLOW_VERSION}`,
  waterline: `packagist://durable-workflow/waterline@${WATERLINE_VERSION}`,
};

const logFile = path.join(RESULT_DIR, 'waterline-worker-status-runner.log');
const cleanupResults = [];
const cleanupFailures = [];
let composeRegistered = false;
let waterlineRegistered = false;
let publishedCommandStarted = false;
let productEvidence = null;
let sourceHygiene = null;
let serverInstall = null;
let sharedServerReceipt = null;
let sharedServerState = null;
let cliInstall = null;
let packageInstall = null;
let runnerError = null;
let sharedIsolationEvidence = null;
const interruption = createInterruptionMonitor();

function env(name) {
  return String(process.env[name] ?? '').trim();
}

function requiredEnv(name) {
  const value = env(name);
  if (!value) throw new Error(`${name} is required`);
  return value;
}

function now() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function normalizeVersion(value) {
  return value.startsWith('v') ? value.slice(1) : value;
}

function truthy(value) {
  return ['1', 'true', 'yes', 'on'].includes(String(value).trim().toLowerCase());
}

function positiveInt(value, fallback) {
  const parsed = Number.parseInt(value, 10);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function errorSummary(error) {
  return boundedDiagnostic(error instanceof Error ? error.message : String(error));
}

function log(message) {
  fs.appendFileSync(logFile, `[${now()}] ${message}\n`, 'utf8');
}

function writeJson(fileName, value) {
  fs.writeFileSync(path.join(RESULT_DIR, fileName), `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function parseJson(text) {
  const trimmed = String(text ?? '').trim();
  if (!trimmed) return null;
  try {
    return JSON.parse(trimmed);
  } catch {
    for (const line of trimmed.split(/\r?\n/).reverse()) {
      try {
        return JSON.parse(line);
      } catch {
        // Look for a final structured line after installer progress.
      }
    }
  }
  return null;
}

function commandExists(command) {
  const result = spawnSync('sh', ['-c', 'command -v "$1" >/dev/null 2>&1', 'sh', command]);
  return result.status === 0;
}

function run(command, args, options = {}) {
  const display = options.display ?? [command, ...args].map((part) => path.basename(String(part))).join(' ');
  log(`command: ${display}`);
  const result = spawnSync(command, args, {
    cwd: options.cwd ?? RUN_ROOT,
    env: options.env ?? process.env,
    encoding: 'utf8',
    maxBuffer: 30 * 1024 * 1024,
    timeout: options.timeout ?? 180_000,
  });
  const record = {
    status: result.status,
    signal: result.signal,
    stdout: result.stdout ?? '',
    stderr: result.stderr ?? '',
  };
  if (!options.allowFailure && result.status !== 0) {
    throw new Error(errorSummary(
      `${display} failed (${result.status}): ${(result.stderr || result.stdout || '').trim()}`,
    ));
  }
  return record;
}

async function freePort() {
  const server = net.createServer();
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  await new Promise((resolve) => server.close(resolve));
  if (!port) throw new Error('could not allocate an observer port');
  return port;
}

function ensureExactPins() {
  const failures = [];
  if (!isExactSemverRelease(SERVER_VERSION)) failures.push('DW_SERVER_VERSION must be an exact SemVer release');
  if (!/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(CLI_VERSION)) failures.push('DW_CLI_VERSION must be exact');
  if (!/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(SDK_PHP_VERSION)) failures.push('DW_PHP_SDK_VERSION must be exact');
  if (!isExact2xPrerelease(WORKFLOW_VERSION)) failures.push('DW_WORKFLOW_PHP_VERSION must be an exact 2.0 prerelease');
  if (!isExact2xPrerelease(WATERLINE_VERSION)) failures.push('DW_WATERLINE_VERSION must be an exact 2.0 prerelease');
  const exactTag = new RegExp(`^(?:(?:docker\\.io|index\\.docker\\.io)/)?durableworkflow/server:${escapeRegex(SERVER_VERSION)}$`).test(SERVER_IMAGE);
  const exactDigest = /^(?:(?:docker\.io|index\.docker\.io)\/)?durableworkflow\/server(?::[^@]+)?@sha256:[0-9a-f]{64}$/i.test(SERVER_IMAGE);
  if (!exactTag && !exactDigest) failures.push('DW_SERVER_IMAGE must be the exact public version tag or a digest pin');
  if (!Number.isInteger(HOST_UID) || !Number.isInteger(HOST_GID)) failures.push('the runner requires a host UID and GID');
  try {
    WATERLINE_HOST = validatedPublishedHost(WATERLINE_HOST);
  } catch (error) {
    failures.push(errorSummary(error));
  }
  if (failures.length > 0) throw new Error(failures.join('; '));
}

function composeEnvironment() {
  return {
    ...process.env,
    DW_SERVER_IMAGE: SERVER_IMAGE,
    DW_AUTH_TOKEN: TOKEN,
    DW_WORKER_HEARTBEAT_INTERVAL_SECONDS: String(HEARTBEAT_SECONDS),
    DW_WORKER_STALE_AFTER_SECONDS: String(STALE_SECONDS),
  };
}

function writeComposeFile(port) {
  fs.writeFileSync(COMPOSE_FILE, `name: ${PROJECT}
x-server-environment: &server-environment
  APP_ENV: local
  APP_DEBUG: "false"
  DB_CONNECTION: mysql
  DB_HOST: mysql
  DB_PORT: "3306"
  DB_DATABASE: durable_workflow
  DB_USERNAME: workflow
  DB_PASSWORD: workflow
  REDIS_HOST: redis
  QUEUE_CONNECTION: redis
  CACHE_STORE: redis
  DW_AUTH_DRIVER: token
  DW_AUTH_TOKEN: \${DW_AUTH_TOKEN}
  DW_AUTH_BACKWARD_COMPATIBLE: "true"
  DW_WORKER_HEARTBEAT_INTERVAL_SECONDS: \${DW_WORKER_HEARTBEAT_INTERVAL_SECONDS}
  DW_WORKER_STALE_AFTER_SECONDS: \${DW_WORKER_STALE_AFTER_SECONDS}
  DW_WORKER_POLL_TIMEOUT: "1"
services:
  bootstrap:
    image: \${DW_SERVER_IMAGE}
    command: ["server-bootstrap"]
    environment: *server-environment
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
  server:
    image: \${DW_SERVER_IMAGE}
    ports:
      - "${port}:8080"
    environment:
      <<: *server-environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: server_http_node
    depends_on:
      bootstrap:
        condition: service_completed_successfully
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/ready"]
      interval: 2s
      timeout: 5s
      retries: 30
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: durable_workflow
      MYSQL_USER: workflow
      MYSQL_PASSWORD: workflow
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 2s
      timeout: 3s
      retries: 60
  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 2s
      timeout: 3s
      retries: 30
volumes:
  mysql_data:
`, 'utf8');
}

function imageMetadata(reference) {
  const inspect = run('docker', ['image', 'inspect', reference], {
    display: `docker image inspect ${reference}`,
    timeout: 60_000,
  });
  const image = parseJson(inspect.stdout)?.[0];
  if (!image?.Id || !String(image.Id).startsWith('sha256:')) {
    throw new Error(`could not resolve pulled server image ${reference}`);
  }
  const publicDigest = (image.RepoDigests ?? []).find((digest) =>
    /^(?:(?:docker\.io|index\.docker\.io)\/)?durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(String(digest)));
  if (!publicDigest) throw new Error(`server image ${reference} has no public durableworkflow/server digest`);
  return {
    id: image.Id,
    digest: String(publicDigest).replace(/^(?:docker\.io|index\.docker\.io)\//i, ''),
  };
}

function detectPhpRuntime(image, label) {
  const detectedAt = now();
  const result = run('docker', [
    'run', '--rm',
    '--entrypoint', 'php',
    image,
    '-r', 'fwrite(STDOUT, PHP_VERSION);',
  ], {
    display: `detect PHP runtime in ${label}`,
    timeout: 60_000,
  });
  const version = String(result.stdout).trim();
  if (!/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(version)) {
    throw new Error(`could not detect an exact PHP runtime version in ${label}: ${version || 'empty output'}`);
  }
  return { image, version, detected_at: detectedAt };
}

async function startServer() {
  if (USE_SHARED_SERVER) return attachSharedServer();
  const port = await freePort();
  writeComposeFile(port);
  composeRegistered = true;
  run('docker', ['pull', SERVER_IMAGE], { display: `docker pull ${SERVER_IMAGE}`, timeout: 300_000 });
  const requested = imageMetadata(SERVER_IMAGE);
  const versionTag = `durableworkflow/server:${SERVER_VERSION}`;
  if (SERVER_IMAGE.includes('@sha256:')) {
    run('docker', ['pull', versionTag], { display: `docker pull ${versionTag}`, timeout: 300_000 });
    const tagged = imageMetadata(versionTag);
    if (tagged.id !== requested.id) throw new Error(`digest pin does not match public version tag ${versionTag}`);
  }
  ARTIFACT_SOURCES.server = `docker://${requested.digest}`;
  const phpRuntime = detectPhpRuntime(SERVER_IMAGE, 'the exact published server image');
  run('docker', ['compose', '-p', PROJECT, '-f', COMPOSE_FILE, 'up', '-d', '--wait', 'server'], {
    env: composeEnvironment(),
    display: 'docker compose up -d --wait server',
    timeout: 420_000,
  });
  const containerResult = run('docker', ['compose', '-p', PROJECT, '-f', COMPOSE_FILE, 'ps', '-q', 'server'], {
    env: composeEnvironment(),
    display: 'docker compose ps -q server',
  });
  const containerId = String(containerResult.stdout).trim();
  if (!containerId) throw new Error('published server container did not start');
  const containerInspect = run('docker', [
    'container', 'inspect', '--format',
    '{"Image":{{json .Image}},"Config":{"Image":{{json .Config.Image}}}}',
    containerId,
  ], { display: 'docker container inspect published server image fields' });
  const container = parseJson(containerInspect.stdout);
  if (container?.Image !== requested.id || container?.Config?.Image !== SERVER_IMAGE) {
    throw new Error('running server container does not match the requested exact published image');
  }
  const bootstrapContainerId = String(run('docker', [
    'compose', '-p', PROJECT, '-f', COMPOSE_FILE, 'ps', '-a', '-q', 'bootstrap',
  ], {
    env: composeEnvironment(),
    display: 'docker compose ps -a -q bootstrap',
    timeout: 30_000,
  }).stdout).trim();
  const bootstrap = bootstrapContainerId
    ? parseJson(run('docker', [
      'container', 'inspect', '--format',
      '{"Config":{"Cmd":{{json .Config.Cmd}}},"State":{{json .State}}}',
      bootstrapContainerId,
    ], { display: 'inspect clean bootstrap completion', timeout: 30_000 }).stdout)
    : null;
  if (bootstrap?.State?.Status !== 'exited'
    || bootstrap?.State?.ExitCode !== 0
    || !Array.isArray(bootstrap?.Config?.Cmd)
    || !bootstrap.Config.Cmd.includes('server-bootstrap')) {
    throw new Error('focused clean published-server bootstrap and migrations did not complete successfully');
  }
  serverInstall = {
    requested_reference: SERVER_IMAGE,
    public_version_tag: versionTag,
    resolved_public_digest: requested.digest,
    resolved_image_id: requested.id,
    running_container_id: containerId,
    running_configured_reference: container.Config.Image,
    running_image_id: container.Image,
    php_runtime: phpRuntime,
    exact_published_image_verified: true,
    bootstrap: {
      mode: 'focused_cell_clean_bootstrap',
      reused: false,
      lifecycle_owner: 'waterline-focused-cell',
      compose_project: PROJECT,
      bootstrap_container_id: bootstrapContainerId,
      configured_command: bootstrap.Config.Cmd,
      container_status: bootstrap.State.Status,
      exit_code: bootstrap.State.ExitCode,
      migrations_completed: true,
    },
  };
  return {
    hostUrl: `http://127.0.0.1:${port}`,
    networkUrl: 'http://server:8080',
    phpVersion: phpRuntime.version,
  };
}

async function attachSharedServer() {
  const statePath = path.resolve(SHARED_SERVER_STATE_FILE);
  if (!fs.existsSync(statePath)) {
    throw new Error(`shared heartbeat server state not found: ${statePath}`);
  }
  const stateBytes = fs.readFileSync(statePath);
  const state = JSON.parse(stateBytes.toString('utf8'));
  sharedServerReceipt = state;
  const failures = sharedServerReceiptFailures(state, {
    serverVersion: SERVER_VERSION,
    serverImage: SERVER_IMAGE,
    namespace: NAMESPACE,
    taskQueue: TASK_QUEUE,
    workerIds: WORKER_IDS,
    workflowIds: WORKFLOW_IDS,
  });
  let hostUrl = null;
  try {
    const parsed = new URL(state?.endpoint?.host_url ?? '');
    hostUrl = parsed.origin;
  } catch {
    // The shared receipt validator reports the actionable endpoint failure.
  }
  if (failures.length > 0) throw new Error(failures.join('; '));

  const image = parseJson(run('docker', ['image', 'inspect', SERVER_IMAGE], {
    display: 'inspect shared published server image',
    timeout: 60_000,
  }).stdout)?.[0];
  const container = parseJson(run('docker', [
    'container', 'inspect', '--format',
    '{"Image":{{json .Image}},"Config":{"Image":{{json .Config.Image}}}}',
    state.server.running_container_id,
  ], {
    display: 'inspect shared published server container',
    timeout: 60_000,
  }).stdout);
  if (image?.Id !== state.server.resolved_image_id
    || container?.Image !== state.server.resolved_image_id
    || container?.Config?.Image !== SERVER_IMAGE) {
    throw new Error('running shared server no longer matches its exact published-image receipt');
  }
  const publicDigest = String(state.server.resolved_public_digest ?? '');
  if (!/^durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(publicDigest)) {
    throw new Error('shared heartbeat server receipt has no canonical public image digest');
  }
  const phpRuntime = detectPhpRuntime(SERVER_IMAGE, 'the exact shared published server image');
  NETWORK = state.compose.network;
  ARTIFACT_SOURCES.server = `docker://${publicDigest}`;
  sharedServerState = state;
  serverInstall = {
    requested_reference: SERVER_IMAGE,
    public_version_tag: state.server.public_version_tag,
    resolved_public_digest: publicDigest,
    resolved_image_id: state.server.resolved_image_id,
    running_container_id: state.server.running_container_id,
    running_configured_reference: container.Config.Image,
    running_image_id: container.Image,
    php_runtime: phpRuntime,
    exact_published_image_verified: true,
    bootstrap: {
      ...state.clean_bootstrap,
      mode: 'shared_wave_clean_bootstrap',
      reused: true,
      wave_run_id: state.wave_run_id,
      lifecycle_owner: state.lifecycle.owner,
      cleanup_status_at_handoff: state.lifecycle.cleanup_status,
    },
    shared_bootstrap_receipt_sha256: crypto.createHash('sha256').update(stateBytes).digest('hex'),
  };
  await waitForHttpReadiness({
    url: `${hostUrl}/api/ready`,
    headers: { Accept: 'application/json', Authorization: `Bearer ${TOKEN}` },
    attemptTimeoutMilliseconds: READINESS_ATTEMPT_SECONDS * 1_000,
    overallTimeoutMilliseconds: 30_000,
    retryDelayMilliseconds: 500,
    signal: interruption.signal,
  });
  return {
    hostUrl,
    networkUrl: state.endpoint.container_url,
    phpVersion: phpRuntime.version,
  };
}

function composer(args, options = {}) {
  return run('docker', [
    'run', '--rm',
    '--user', CONTAINER_USER,
    '--env', 'HOME=/tmp',
    '--env', 'COMPOSER_HOME=/work/composer-home',
    '--env', 'COMPOSER_MEMORY_LIMIT=-1',
    '-v', `${RUN_ROOT}:/work`,
    '-w', options.workdir ?? '/work',
    COMPOSER_IMAGE,
    ...args,
  ], {
    display: `composer ${args[0] ?? ''}`,
    timeout: options.timeout ?? 600_000,
  });
}

function installPackages(serverPhpVersion) {
  const composerPhpRuntime = detectPhpRuntime(COMPOSER_IMAGE, 'the Composer installer image');
  fs.mkdirSync(COMPOSER_HOME_DIR, { recursive: true });
  sourceHygiene = {
    checked_at: null,
    composer_preferred_install: null,
    relevant_packages: null,
    configured_repositories: null,
    local_path_repository_present: null,
    local_checkout_markers: null,
    local_product_source_checkouts_used: null,
    php_runtime_alignment: {
      server_image: SERVER_IMAGE,
      server_detected_php: serverPhpVersion,
      composer_image: COMPOSER_IMAGE,
      composer_detected_php: composerPhpRuntime.version,
      composer_global_platform_configured_php: null,
      composer_platform_configured_php: null,
      platform_matches_server: false,
      detected_before_dependency_resolution: true,
    },
    dependency_resolution_completed: false,
    server_runtime_boot: { passed: false, status: 'pending' },
    passed: false,
  };
  packageInstall = {
    installer_runtime: COMPOSER_IMAGE,
    install_mode: 'composer create-project --no-install --no-scripts plus exact prefer-dist requirements and update',
    php_runtime_alignment: { ...sourceHygiene.php_runtime_alignment },
    laravel_host_package: null,
    packages: null,
    server_runtime_boot: { passed: false, status: 'pending' },
  };

  // Pin project discovery as well as dependency resolution. The persisted,
  // disposable Composer home makes this global setting available to the
  // create-project container before an application composer.json exists.
  composer(['config', '--global', 'platform.php', serverPhpVersion]);
  const composerGlobalConfig = JSON.parse(fs.readFileSync(path.join(COMPOSER_HOME_DIR, 'config.json'), 'utf8'));
  const configuredGlobalPlatformPhp = String(composerGlobalConfig.config?.platform?.php ?? '');
  sourceHygiene.php_runtime_alignment.composer_global_platform_configured_php = configuredGlobalPlatformPhp;
  packageInstall.php_runtime_alignment.composer_global_platform_configured_php = configuredGlobalPlatformPhp;
  if (configuredGlobalPlatformPhp !== serverPhpVersion) {
    throw new Error(`Composer global platform PHP ${configuredGlobalPlatformPhp || 'is not configured'}; expected exact server runtime ${serverPhpVersion}`);
  }

  // Download the Laravel host skeleton without resolving its dependencies under
  // the Composer image's PHP or dispatching skeleton hooks that require vendor
  // dependencies. All dependency resolution and script execution happens after
  // the exact server runtime has been configured as Composer's platform PHP.
  composer(['create-project', '--no-install', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist', 'laravel/laravel:^12.0', 'app'], { timeout: 900_000 });
  composer(['config', 'minimum-stability', 'dev'], { workdir: '/work/app' });
  composer(['config', 'prefer-stable', 'true'], { workdir: '/work/app' });
  composer(['config', 'preferred-install', 'dist'], { workdir: '/work/app' });
  composer(['config', 'allow-plugins.php-http/discovery', 'true'], { workdir: '/work/app' });
  composer(['config', 'platform.php', serverPhpVersion], { workdir: '/work/app' });
  sourceHygiene.php_runtime_alignment.composer_platform_configured_php = serverPhpVersion;
  sourceHygiene.php_runtime_alignment.platform_matches_server = true;
  packageInstall.php_runtime_alignment.composer_platform_configured_php = serverPhpVersion;
  packageInstall.php_runtime_alignment.platform_matches_server = true;
  composer([
    'require', '--no-update', '--no-interaction',
    `durable-workflow/sdk:${SDK_PHP_VERSION}`,
    `durable-workflow/workflow:${WORKFLOW_VERSION}`,
    `durable-workflow/waterline:${WATERLINE_VERSION}`,
  ], { workdir: '/work/app' });
  composer([
    'update', '--no-interaction', '--no-progress', '--prefer-dist', '--with-all-dependencies',
  ], { workdir: '/work/app', timeout: 900_000 });

  const composerJson = JSON.parse(fs.readFileSync(path.join(APP_DIR, 'composer.json'), 'utf8'));
  const composerLockText = fs.readFileSync(path.join(APP_DIR, 'composer.lock'), 'utf8');
  const composerLock = JSON.parse(composerLockText);
  const packages = [...(composerLock.packages ?? []), ...(composerLock['packages-dev'] ?? [])];
  const relevant = Object.fromEntries(['durable-workflow/sdk', 'durable-workflow/workflow', 'durable-workflow/waterline'].map((name) => {
    const entry = packages.find((candidate) => candidate.name === name);
    if (!entry) throw new Error(`composer.lock does not contain ${name}`);
    return [name, {
      version: normalizeVersion(String(entry.version ?? '')),
      dist: entry.dist ?? null,
      source: entry.source ?? null,
    }];
  }));
  if (relevant['durable-workflow/sdk'].version !== SDK_PHP_VERSION) {
    throw new Error('installed PHP SDK package does not match the exact requested version');
  }
  if (relevant['durable-workflow/workflow'].version !== WORKFLOW_VERSION) {
    throw new Error('installed Workflow PHP package does not match the exact requested version');
  }
  if (relevant['durable-workflow/waterline'].version !== WATERLINE_VERSION) {
    throw new Error('installed Waterline package does not match the exact requested version');
  }
  const configuredPlatformPhp = String(composerJson.config?.platform?.php ?? '');
  sourceHygiene.php_runtime_alignment.composer_platform_configured_php = configuredPlatformPhp;
  sourceHygiene.php_runtime_alignment.platform_matches_server = configuredPlatformPhp === serverPhpVersion;
  packageInstall.php_runtime_alignment = { ...sourceHygiene.php_runtime_alignment };
  if (configuredPlatformPhp !== serverPhpVersion) {
    throw new Error(`Composer platform PHP ${configuredPlatformPhp || 'is not configured'}; expected exact server runtime ${serverPhpVersion}`);
  }
  const configuredRepositories = composerJson.repositories ?? [];
  const localPathRepository = JSON.stringify(configuredRepositories).toLowerCase().includes('"type":"path"');
  const checkoutMarkers = ['file://', 'path repository', '"type":"path"', '../repos/'];
  const scanned = `${JSON.stringify(composerJson)}\n${composerLockText}`.toLowerCase();
  const matchedMarkers = checkoutMarkers.filter((marker) => scanned.includes(marker));
  const publishedPackageSourcesPassed = !localPathRepository && matchedMarkers.length === 0
    && Object.values(relevant).every((entry) => Boolean(entry.dist));
  sourceHygiene = {
    ...sourceHygiene,
    checked_at: now(),
    composer_preferred_install: composerJson.config?.['preferred-install'] ?? 'dist',
    relevant_packages: relevant,
    configured_repositories: configuredRepositories,
    local_path_repository_present: localPathRepository,
    local_checkout_markers: matchedMarkers,
    local_product_source_checkouts_used: false,
    published_package_sources_passed: publishedPackageSourcesPassed,
    dependency_resolution_completed: true,
    passed: false,
  };
  if (!publishedPackageSourcesPassed) throw new Error('published package source hygiene checks failed');
  packageInstall = {
    ...packageInstall,
    installer_runtime: COMPOSER_IMAGE,
    laravel_host_package: packages.find((candidate) => candidate.name === 'laravel/framework')?.version ?? null,
    packages: relevant,
  };
  writeJson('source-hygiene.json', sourceHygiene);
}

function parseCliVersionOutput(output) {
  const match = String(output ?? '').trim().match(/(?:^|\s)v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)(?=$|\s|\))/);
  return match ? normalizeVersion(match[1]) : '';
}

function installCli() {
  fs.mkdirSync(path.join(CLI_DIR, 'bin'), { recursive: true });
  const installer = path.join(CLI_DIR, 'install.sh');
  let sourceUrl = '';
  for (const tag of [CLI_VERSION, `v${CLI_VERSION}`]) {
    const url = `https://github.com/durable-workflow/cli/releases/download/${tag}/install.sh`;
    const download = run('curl', ['--fail', '--location', '--silent', '--show-error', url, '--output', installer], {
      allowFailure: true,
      display: `download official dw ${CLI_VERSION} installer`,
      timeout: 90_000,
    });
    if (download.status === 0) {
      sourceUrl = url;
      break;
    }
  }
  if (!sourceUrl) throw new Error(`could not download official dw ${CLI_VERSION} installer`);
  fs.chmodSync(installer, 0o755);
  run('sh', [installer], {
    env: {
      ...process.env,
      VERSION: CLI_VERSION,
      DURABLE_WORKFLOW_INSTALL_DIR: path.join(CLI_DIR, 'bin'),
      DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS: '0',
    },
    display: `install official dw ${CLI_VERSION}`,
    timeout: 180_000,
  });
  const binary = path.join(CLI_DIR, 'bin', os.platform() === 'win32' ? 'dw.exe' : 'dw');
  const version = run(binary, ['--version'], { display: 'dw --version', timeout: 30_000 });
  const output = String(version.stdout || version.stderr).trim();
  const installedVersion = parseCliVersionOutput(output);
  if (installedVersion !== CLI_VERSION) {
    throw new Error(`published CLI mismatch: expected ${CLI_VERSION}, got ${output || 'empty'}`);
  }
  ARTIFACT_SOURCES.cli = sourceUrl;
  cliInstall = {
    requested_version: CLI_VERSION,
    detected_version: installedVersion,
    version_output: output,
    source_url: sourceUrl,
    binary: path.basename(binary),
  };
}

function waterlineEnvironment(waterlineHostUrl = 'http://waterline:8000') {
  return {
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_KEY: 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=',
    APP_URL: waterlineHostUrl,
    DB_CONNECTION: 'mysql',
    DB_HOST: 'mysql',
    DB_PORT: '3306',
    DB_DATABASE: 'durable_workflow',
    DB_USERNAME: 'workflow',
    DB_PASSWORD: 'workflow',
    DW_WV_WATERLINE_DB_DRIVER: 'mysql',
    DW_WV_WATERLINE_DB_HOST: 'mysql',
    DW_WV_WATERLINE_DB_PORT: '3306',
    DW_WV_WATERLINE_DB_DATABASE: 'durable_workflow',
    DW_WV_WATERLINE_DB_USERNAME: 'workflow',
    DW_WV_WATERLINE_DB_PASSWORD: 'workflow',
    DW_V2_TASK_DISPATCH_MODE: 'poll',
    QUEUE_CONNECTION: 'sync',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'array',
    WATERLINE_ALLOW_UNAUTHENTICATED: 'true',
    WATERLINE_ENGINE_SOURCE: 'v2',
    WATERLINE_HEALTH_TASK_DISPATCH_MODE: 'poll',
    WATERLINE_NAMESPACE: NAMESPACE,
    WATERLINE_WORKER_STALE_AFTER_SECONDS: String(STALE_SECONDS),
  };
}

function dockerEnvironmentArgs(values) {
  return Object.keys(values).flatMap((name) => ['--env', name]);
}

function verifyInstalledAppBoot(serverPhpVersion) {
  const runtimeEnv = waterlineEnvironment();
  const result = run('docker', [
    'run', '--rm',
    '--user', CONTAINER_USER,
    '--network', NETWORK,
    ...dockerEnvironmentArgs(runtimeEnv),
    '-v', `${APP_DIR}:/app`,
    '-w', '/app',
    '--entrypoint', 'php',
    SERVER_IMAGE,
    'artisan', '--version',
  ], {
    env: { ...process.env, ...runtimeEnv },
    allowFailure: true,
    display: 'boot installed Laravel host in exact published server image',
    timeout: 60_000,
  });
  const verification = {
    server_image: SERVER_IMAGE,
    detected_php: serverPhpVersion,
    configured_composer_platform_php: sourceHygiene?.php_runtime_alignment?.composer_platform_configured_php ?? null,
    command: 'php artisan --version',
    exit_code: result.status,
    stdout: String(result.stdout).trim(),
    stderr: String(result.stderr).trim(),
    verified_at: now(),
    passed: result.status === 0,
  };
  serverInstall.installed_app_boot = verification;
  packageInstall.server_runtime_boot = verification;
  sourceHygiene.server_runtime_boot = verification;
  sourceHygiene.passed = sourceHygiene.published_package_sources_passed === true
    && sourceHygiene.php_runtime_alignment.platform_matches_server === true
    && verification.passed;
  writeJson('source-hygiene.json', sourceHygiene);
  if (!verification.passed) {
    throw new Error(`installed Waterline host cannot boot under server PHP ${serverPhpVersion}: ${verification.stderr || verification.stdout || 'empty output'}`);
  }
}

async function startWaterlineHost() {
  const port = await freePort();
  const topology = waterlinePublishedTopology(WATERLINE_HOST, port, WATERLINE_CONTAINER);
  const runtimeEnv = waterlineEnvironment(topology.appUrl);
  waterlineRegistered = true;
  run('docker', [
    'run', '-d', '--name', WATERLINE_CONTAINER,
    '--user', CONTAINER_USER,
    '--network', NETWORK,
    '-p', `${port}:8000`,
    ...dockerEnvironmentArgs(runtimeEnv),
    '-v', `${APP_DIR}:/app`,
    '-w', '/app',
    '--entrypoint', 'php',
    SERVER_IMAGE,
    '-d', 'variables_order=EGPCS',
    'artisan', 'serve', '--host=0.0.0.0', '--port=8000',
  ], {
    env: { ...process.env, ...runtimeEnv },
    display: 'start published Waterline Laravel package host',
    timeout: 60_000,
  });
  try {
    await waitForHttpReadiness({
      url: `${topology.externalHostUrl}/waterline/api/v2/health`,
      headers: { Accept: 'application/json', 'X-Durable-Workflow-Control-Plane-Version': '2' },
      attemptTimeoutMilliseconds: READINESS_ATTEMPT_SECONDS * 1_000,
      overallTimeoutMilliseconds: READINESS_DEADLINE_SECONDS * 1_000,
      retryDelayMilliseconds: 1_000,
      signal: interruption.signal,
    });
  } catch (error) {
    if (interruption.signal.aborted) throw error;
    const logs = run('docker', ['logs', WATERLINE_CONTAINER], {
      allowFailure: true,
      display: 'docker logs Waterline host',
      timeout: 30_000,
    });
    throw new Error(errorSummary(`${errorSummary(error)}\n${logs.stdout}${logs.stderr}`));
  }

  return {
    hostUrl: topology.externalHostUrl,
    networkUrl: topology.containerNetworkUrl,
  };
}

function runPublishedCommand(server, waterline) {
  const runtimeEnv = {
    ...waterlineEnvironment(waterline.networkUrl),
    DURABLE_WORKFLOW_AUTH_TOKEN: TOKEN,
  };
  publishedCommandStarted = true;
  const result = run('docker', [
    'run', '--rm',
    '--user', CONTAINER_USER,
    '--network', NETWORK,
    ...dockerEnvironmentArgs(runtimeEnv),
    '-v', `${APP_DIR}:/app`,
    '-v', `${path.join(CLI_DIR, 'bin')}:/cli:ro`,
    '-v', `${RESULT_DIR}:/results`,
    '-w', '/app',
    '--entrypoint', 'php',
    SERVER_IMAGE,
    'artisan', 'waterline:worker-status-conformance',
    `--server-url=${server.networkUrl}`,
    `--waterline-url=${waterline.networkUrl}`,
    `--namespace=${NAMESPACE}`,
    `--task-queue=${TASK_QUEUE}`,
    `--run-id=${RUN_ID}`,
    '--cli-bin=/cli/dw',
    `--server-version=${SERVER_VERSION}`,
    `--cli-version=${CLI_VERSION}`,
    `--sdk-php-version=${SDK_PHP_VERSION}`,
    `--workflow-version=${WORKFLOW_VERSION}`,
    `--waterline-version=${WATERLINE_VERSION}`,
    `--server-source=${ARTIFACT_SOURCES.server}`,
    `--cli-source=${ARTIFACT_SOURCES.cli}`,
    `--sdk-php-source=${ARTIFACT_SOURCES['sdk-php']}`,
    `--workflow-source=${ARTIFACT_SOURCES.workflow}`,
    `--waterline-source=${ARTIFACT_SOURCES.waterline}`,
    `--heartbeat-interval=${HEARTBEAT_SECONDS}`,
    `--stale-after=${STALE_SECONDS}`,
    '--output=/results/waterline-worker-status-evidence.json',
  ], {
    env: { ...process.env, ...runtimeEnv },
    allowFailure: true,
    display: 'php artisan waterline:worker-status-conformance [published endpoints and exact pins]',
    timeout: (STALE_SECONDS + 180) * 1_000,
  });
  if (fs.existsSync(EVIDENCE_PATH)) {
    productEvidence = JSON.parse(fs.readFileSync(EVIDENCE_PATH, 'utf8'));
  }
  if (result.status !== 0) {
    const message = productEvidence?.findings?.[0]?.message
      ?? (result.stderr || result.stdout || '').trim();
    throw new Error(`published Waterline worker-status command failed (${result.status}): ${message}`);
  }
  if (!productEvidence || productEvidence.outcome !== 'pass' || productEvidence.runner_blocked !== false) {
    throw new Error('published command exited successfully without passing non-runner-blocked evidence');
  }
  if (productEvidence?.topology?.stale_worker_id !== WORKER_IDS.stale_worker_id
    || productEvidence?.topology?.fresh_worker_id !== WORKER_IDS.fresh_worker_id) {
    throw new Error('published command evidence does not match the validated Waterline worker-ID plan');
  }
  if (productEvidence?.topology?.initial_workflow_id !== WORKFLOW_IDS.initial_workflow_id
    || productEvidence?.topology?.after_stale_workflow_id
      !== WORKFLOW_IDS.after_stale_workflow_id) {
    throw new Error('published command evidence does not match the validated Waterline workflow-ID plan');
  }
  if (USE_SHARED_SERVER) {
    sharedIsolationEvidence = verifySharedWaterlineIsolation(
      sharedServerState,
      productEvidence,
      WORKFLOW_IDS,
    );
    if (!sharedIsolationEvidence.passed) {
      const failedChecks = Object.entries(sharedIsolationEvidence.checks)
        .filter(([, passed]) => passed !== true)
        .map(([check]) => check);
      throw new Error(
        `published Waterline identities do not satisfy the shared-wave receipt: ${failedChecks.join(', ')}`,
      );
    }
  }
}

function cleanupWaterline() {
  if (!waterlineRegistered) return { resource: 'waterline_host', status: 'not_started' };
  const removal = run('docker', ['rm', '-f', WATERLINE_CONTAINER], {
    allowFailure: true,
    display: 'docker rm -f Waterline host',
    timeout: 30_000,
  });
  const inspect = run('docker', ['container', 'inspect', WATERLINE_CONTAINER], {
    allowFailure: true,
    display: 'verify Waterline host removal',
    timeout: 30_000,
  });
  if (inspect.status === 0) throw new Error(`Waterline host container remains after cleanup: ${removal.stderr}`);
  if (removal.status !== 0 && !/no such (?:object|container)/i.test(String(inspect.stderr))) {
    throw new Error(`Waterline host cleanup could not be verified: ${(inspect.stderr || removal.stderr).trim()}`);
  }
  return { resource: 'waterline_host', name: WATERLINE_CONTAINER, status: 'removed' };
}

function cleanupCompose() {
  if (USE_SHARED_SERVER) {
    return {
      resource: 'shared_server',
      name: sharedServerState?.compose?.project ?? null,
      status: 'retained_for_wave_cleanup',
      lifecycle_owner: sharedServerState?.lifecycle?.owner ?? null,
    };
  }
  if (!composeRegistered) return { resource: 'compose_project', status: 'not_started' };
  const down = run('docker', ['compose', '-p', PROJECT, '-f', COMPOSE_FILE, 'down', '-v', '--remove-orphans'], {
    env: composeEnvironment(),
    allowFailure: true,
    display: 'docker compose down -v --remove-orphans',
    timeout: 180_000,
  });
  const resourceTypes = [
    {
      name: 'containers',
      list: ['ps', '-aq', '--filter', `label=com.docker.compose.project=${PROJECT}`],
      remove: (name) => ['rm', '-f', name],
    },
    {
      name: 'volumes',
      list: ['volume', 'ls', '--filter', `label=com.docker.compose.project=${PROJECT}`, '--format', '{{.Name}}'],
      remove: (name) => ['volume', 'rm', '-f', name],
    },
    {
      name: 'networks',
      list: ['network', 'ls', '--filter', `label=com.docker.compose.project=${PROJECT}`, '--format', '{{.Name}}'],
      remove: (name) => ['network', 'rm', name],
    },
  ];
  const removedByFallback = { containers: [], volumes: [], networks: [] };
  const failures = [];

  for (const resource of resourceTypes) {
    const listed = run('docker', resource.list, {
      allowFailure: true,
      display: `list labeled compose ${resource.name}`,
      timeout: 30_000,
    });
    if (listed.status !== 0) {
      failures.push(`${resource.name} listing failed: ${(listed.stderr || listed.stdout).trim()}`);
      continue;
    }
    for (const name of String(listed.stdout).split(/\r?\n/).map((value) => value.trim()).filter(Boolean)) {
      const removal = run('docker', resource.remove(name), {
        allowFailure: true,
        display: `remove labeled compose ${resource.name}`,
        timeout: 30_000,
      });
      if (removal.status === 0) removedByFallback[resource.name].push(name);
      else failures.push(`${resource.name} ${name} removal failed: ${(removal.stderr || removal.stdout).trim()}`);
    }
  }

  const remaining = {};
  for (const resource of resourceTypes) {
    const listed = run('docker', resource.list, {
      allowFailure: true,
      display: `verify labeled compose ${resource.name} cleanup`,
      timeout: 30_000,
    });
    if (listed.status !== 0) failures.push(`${resource.name} cleanup verification failed`);
    remaining[resource.name] = String(listed.stdout).trim();
  }
  if (Object.values(remaining).some(Boolean)) {
    failures.push(`compose cleanup left labeled resources: ${JSON.stringify(remaining)}`);
  }
  if (failures.length > 0) throw new Error(failures.join('; '));

  return {
    resource: 'compose_project',
    name: PROJECT,
    status: down.status === 0 ? 'removed_with_volumes_and_network' : 'removed_with_labeled_fallback',
    compose_down_exit_code: down.status,
    fallback_removed: removedByFallback,
  };
}

function removeDirectory(directory) {
  fs.rmSync(directory, { recursive: true, force: true });
}

function finalResult() {
  const cleanupPassed = cleanupFailures.length === 0;
  const productPassed = productEvidence?.outcome === 'pass' && productEvidence?.runner_blocked === false;
  const workerIdPrefixBinding = workerIdPrefixBindingEvidence(
    sharedServerReceipt,
    WORKER_IDS,
    productEvidence,
  );
  const workflowIdPrefixBinding = workflowIdPrefixBindingEvidence(
    sharedServerReceipt,
    WORKFLOW_IDS,
    productEvidence,
  );
  let outcome = 'non_passing_runner_blocked';
  let runnerBlocked = true;
  let classification = 'waterline-worker-status-runner-blocked';
  if (productPassed && cleanupPassed && !runnerError) {
    outcome = 'pass';
    runnerBlocked = false;
    classification = 'published-waterline-worker-status-proven';
  } else if (
    !runnerError?.interrupted_by
    && productEvidence
    && productEvidence.runner_blocked === false
    && productEvidence.outcome !== 'pass'
  ) {
    outcome = 'fail';
    runnerBlocked = false;
    classification = productEvidence.classification ?? 'published-waterline-worker-status-product-failure';
  }
  if (!cleanupPassed) {
    outcome = 'non_passing_runner_blocked';
    runnerBlocked = true;
    classification = 'waterline-worker-status-cleanup-blocked';
  }
  return {
    schema: 'durable-workflow.v2.waterline-worker-status-run-result',
    version: 1,
    scenario_id: 'waterline_worker_status_visibility',
    conformance_run_id: RUN_ID,
    started_at: STARTED_AT,
    finished_at: now(),
    outcome,
    runner_blocked: runnerBlocked,
    classification,
    artifact_versions: ARTIFACT_VERSIONS,
    artifact_sources: ARTIFACT_SOURCES,
    topology: {
      namespace: NAMESPACE,
      task_queue: TASK_QUEUE,
      heartbeat_interval_seconds: HEARTBEAT_SECONDS,
      stale_after_seconds: STALE_SECONDS,
      readiness_attempt_timeout_seconds: READINESS_ATTEMPT_SECONDS,
      readiness_deadline_seconds: READINESS_DEADLINE_SECONDS,
      shared_wave_run_id: sharedServerReceipt?.wave_run_id ?? null,
      isolation: {
        prescribed_namespace: sharedServerReceipt?.cell_isolation?.waterline?.namespace ?? null,
        task_queue_prefix: sharedServerReceipt?.cell_isolation?.waterline?.task_queue_prefix ?? null,
        ...workerIdPrefixBinding,
        ...workflowIdPrefixBinding,
        verification: sharedIsolationEvidence,
      },
    },
    installs: {
      server: serverInstall,
      cli: cliInstall,
      composer_packages: packageInstall,
    },
    source_hygiene: sourceHygiene,
    local_product_source_checkouts_used: false,
    product_evidence_file: productEvidence ? path.basename(EVIDENCE_PATH) : null,
    product_evidence: productEvidence,
    cleanup: { results: cleanupResults, failures: cleanupFailures },
    runner_error: runnerError,
  };
}

await runWithCleanup(async () => {
  try {
    if (interruption.signal.aborted) throw interruption.signal.reason;
    fs.rmSync(EVIDENCE_PATH, { force: true });
    writeJson('pins.json', {
      schema: 'durable-workflow.v2.waterline-worker-status-pins',
      version: 1,
      conformance_run_id: RUN_ID,
      resolved_at: STARTED_AT,
      artifact_versions: ARTIFACT_VERSIONS,
      artifact_sources: ARTIFACT_SOURCES,
    });
    ensureExactPins();
    for (const command of ['docker', 'curl']) {
      if (!commandExists(command)) throw new Error(`required command not found: ${command}`);
    }
    const server = await startServer();
    installCli();
    installPackages(server.phpVersion);
    verifyInstalledAppBoot(server.phpVersion);
    const waterline = await startWaterlineHost();
    runPublishedCommand(server, waterline);
    if (interruption.signal.aborted) throw interruption.signal.reason;
  } catch (error) {
    runnerError = {
      message: errorSummary(error),
      observed_at: now(),
      published_command_started: publishedCommandStarted,
      interrupted_by: interruption.interruptedBy(),
    };
    log(`failure: ${error instanceof Error ? error.stack ?? error.message : String(error)}`);
    process.exitCode = interruption.interruptedBy() === 'SIGINT'
      ? 130
      : interruption.interruptedBy() === 'SIGTERM' ? 143 : 1;
  }
}, async () => {
  const interruptedBy = interruption.interruptedBy();
  if (interruptedBy && !runnerError) {
    runnerError = {
      message: errorSummary(interruption.signal.reason),
      observed_at: now(),
      published_command_started: publishedCommandStarted,
      interrupted_by: interruptedBy,
    };
    process.exitCode = interruptedBy === 'SIGINT' ? 130 : 143;
  }

  try {
    cleanupResults.push(cleanupWaterline());
  } catch (error) {
    cleanupFailures.push({ resource: 'waterline_host', error: errorSummary(error) });
    process.exitCode = 1;
  }
  try {
    cleanupResults.push(cleanupCompose());
  } catch (error) {
    cleanupFailures.push({ resource: 'compose_project', error: errorSummary(error) });
    process.exitCode = 1;
  }
  if (!KEEP_RUN_ROOT) {
    try {
      removeDirectory(RUN_ROOT);
      cleanupResults.push({ resource: 'run_root', status: 'removed' });
    } catch (error) {
      cleanupFailures.push({ resource: 'run_root', error: errorSummary(error) });
      process.exitCode = 1;
    }
  } else {
    cleanupResults.push({ resource: 'run_root', status: 'retained_by_request' });
  }

  const result = finalResult();
  fs.writeFileSync(RESULT_PATH, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
  writeJson('source-hygiene.json', sourceHygiene ?? {
    checked_at: result.finished_at,
    passed: false,
    local_product_source_checkouts_used: null,
    reason: 'published Composer installation did not complete',
  });
  writeJson('run-metadata.json', {
    schema: 'durable-workflow.v2.waterline-worker-status-run-metadata',
    version: 1,
    conformance_run_id: RUN_ID,
    started_at: STARTED_AT,
    finished_at: result.finished_at,
    outcome: result.outcome,
    runner_blocked: result.runner_blocked,
    cleanup: result.cleanup,
  });
  writeJson('pins.json', {
    schema: 'durable-workflow.v2.waterline-worker-status-pins',
    version: 1,
    conformance_run_id: RUN_ID,
    resolved_at: result.finished_at,
    artifact_versions: ARTIFACT_VERSIONS,
    artifact_sources: ARTIFACT_SOURCES,
  });
  if (result.outcome !== 'pass' && !process.exitCode) process.exitCode = 1;
  interruption.dispose();
});
