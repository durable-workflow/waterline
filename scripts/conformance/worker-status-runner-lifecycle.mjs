const DEFAULT_DIAGNOSTIC_BYTES = 2_000;

export class RunnerInterruptedError extends Error {
  constructor(signal) {
    super(`published worker-status runner interrupted by ${signal}`);
    this.name = 'RunnerInterruptedError';
    this.signal = signal;
  }
}

export function boundedDiagnostic(value, maximumBytes = DEFAULT_DIAGNOSTIC_BYTES) {
  const input = String(value ?? '');
  if (Buffer.byteLength(input, 'utf8') <= maximumBytes) return input;

  let output = '';
  let bytes = 0;
  for (const character of input) {
    const characterBytes = Buffer.byteLength(character, 'utf8');
    if (bytes + characterBytes > maximumBytes) break;
    output += character;
    bytes += characterBytes;
  }
  return output;
}

export function createInterruptionMonitor(processObject = process) {
  const controller = new AbortController();
  let interruptedBy = null;
  const listeners = new Map();

  for (const signal of ['SIGINT', 'SIGTERM']) {
    const listener = () => {
      if (interruptedBy !== null) return;
      interruptedBy = signal;
      controller.abort(new RunnerInterruptedError(signal));
    };
    listeners.set(signal, listener);
    processObject.on(signal, listener);
  }

  return {
    signal: controller.signal,
    interruptedBy: () => interruptedBy,
    dispose: () => {
      for (const [signal, listener] of listeners) {
        processObject.removeListener(signal, listener);
      }
    },
  };
}

function interruptionError(signal) {
  if (signal?.reason instanceof Error) return signal.reason;
  return new Error('published worker-status runner interrupted');
}

function abortableDelay(milliseconds, signal) {
  if (signal?.aborted) return Promise.reject(interruptionError(signal));

  return new Promise((resolve, reject) => {
    const timer = setTimeout(finish, milliseconds);
    const onAbort = () => finish(interruptionError(signal));

    function finish(error = null) {
      clearTimeout(timer);
      signal?.removeEventListener('abort', onAbort);
      if (error) reject(error);
      else resolve();
    }

    signal?.addEventListener('abort', onAbort, { once: true });
  });
}

async function requestWithTimeout(fetchImpl, url, options, timeoutMilliseconds, signal) {
  if (signal?.aborted) throw interruptionError(signal);

  const requestController = new AbortController();
  let timeout;
  let rejectInterruption;
  const timeoutPromise = new Promise((_, reject) => {
    timeout = setTimeout(() => {
      requestController.abort(new Error(`readiness request timed out after ${timeoutMilliseconds}ms`));
      reject(new Error(`readiness request timed out after ${timeoutMilliseconds}ms`));
    }, timeoutMilliseconds);
  });
  const interruptionPromise = new Promise((_, reject) => {
    rejectInterruption = reject;
  });
  const onAbort = () => {
    const error = interruptionError(signal);
    requestController.abort(error);
    rejectInterruption(error);
  };
  signal?.addEventListener('abort', onAbort, { once: true });

  const requestPromise = Promise.resolve()
    .then(() => fetchImpl(url, { ...options, signal: requestController.signal }))
    .then(async (response) => ({
      ok: response.ok,
      status: response.status,
      body: await response.text(),
    }));

  try {
    return await Promise.race([requestPromise, timeoutPromise, interruptionPromise]);
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener('abort', onAbort);
  }
}

export async function waitForHttpReadiness({
  url,
  headers = {},
  fetchImpl = fetch,
  attemptTimeoutMilliseconds,
  overallTimeoutMilliseconds,
  retryDelayMilliseconds,
  signal,
}) {
  const deadline = Date.now() + overallTimeoutMilliseconds;
  let attempts = 0;
  let lastObservation = 'no readiness request completed';

  while (Date.now() < deadline) {
    if (signal?.aborted) throw interruptionError(signal);
    attempts += 1;
    const remaining = deadline - Date.now();
    const attemptTimeout = Math.max(1, Math.min(attemptTimeoutMilliseconds, remaining));

    try {
      const observation = await requestWithTimeout(
        fetchImpl,
        url,
        { headers },
        attemptTimeout,
        signal,
      );
      lastObservation = `${observation.status}: ${observation.body}`;
      if (observation.ok) return { ...observation, attempts };
    } catch (error) {
      if (signal?.aborted) throw interruptionError(signal);
      lastObservation = error instanceof Error ? error.message : String(error);
    }

    const delay = Math.min(retryDelayMilliseconds, Math.max(0, deadline - Date.now()));
    if (delay > 0) await abortableDelay(delay, signal);
  }

  throw new Error(
    `published Waterline host did not become ready after ${attempts} attempts: ${lastObservation}`,
  );
}

export async function runWithCleanup(operation, cleanup) {
  try {
    return await operation();
  } finally {
    await cleanup();
  }
}
