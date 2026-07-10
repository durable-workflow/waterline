import net from 'node:net';

const DEFAULT_PUBLISHED_HOST = '127.0.0.1';
const HOSTNAME_LABEL = /^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/;
const CONTAINER_NAME = /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/;

function invalidHost() {
  return new Error('DW_WATERLINE_HOST must be an IP address or hostname without a scheme, port, path, query, or fragment');
}

export function validatedPublishedHost(value) {
  let host = String(value ?? '').trim();
  if (!host) return DEFAULT_PUBLISHED_HOST;

  const opensBracket = host.startsWith('[');
  const closesBracket = host.endsWith(']');
  if (opensBracket || closesBracket) {
    if (!opensBracket || !closesBracket) throw invalidHost();
    host = host.slice(1, -1);
  }

  if (net.isIP(host) !== 0) return host;

  // Do not accidentally accept a malformed IPv4 literal as a DNS name.
  if (/^[0-9.]+$/.test(host)) throw invalidHost();
  if (host.length > 253 || host.endsWith('.')) throw invalidHost();
  const labels = host.split('.');
  if (labels.some((label) => !HOSTNAME_LABEL.test(label))) throw invalidHost();

  return host.toLowerCase();
}

export function publishedHttpUrl(host, port) {
  const validatedHost = validatedPublishedHost(host);
  if (!Number.isInteger(port) || port < 1 || port > 65_535) {
    throw new Error('published Waterline port must be an integer from 1 through 65535');
  }
  const authority = net.isIP(validatedHost) === 6 ? `[${validatedHost}]` : validatedHost;
  return `http://${authority}:${port}`;
}

export function waterlinePublishedTopology(host, port, containerName) {
  if (!CONTAINER_NAME.test(String(containerName))) {
    throw new Error('Waterline container name is invalid');
  }
  const externalHostUrl = publishedHttpUrl(host, port);
  return Object.freeze({
    externalHostUrl,
    appUrl: externalHostUrl,
    containerNetworkUrl: `http://${containerName}:8000`,
  });
}
