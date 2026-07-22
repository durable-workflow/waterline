const EXACT_2X_PRERELEASE = /^2\.0\.0-(?:alpha|beta|rc)\.(?:0|[1-9]\d*)$/;
const CORE_IDENTIFIER = '(?:0|[1-9]\\d*)';
const PRERELEASE_IDENTIFIER = '(?:0|[1-9]\\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)';
const ROLLING_RELEASE_IDENTIFIERS = new Set([
  'latest', 'current', 'head', 'main', 'master', 'dev', 'snapshot', 'unresolved', 'placeholder',
]);
const EXACT_SEMVER_RELEASE = new RegExp(
  `^${CORE_IDENTIFIER}\\.${CORE_IDENTIFIER}\\.${CORE_IDENTIFIER}`
  + `(?:-${PRERELEASE_IDENTIFIER}(?:\\.${PRERELEASE_IDENTIFIER})*)?$`,
);

export function isExact2xPrerelease(version) {
  return EXACT_2X_PRERELEASE.test(version);
}

export function isExactSemverRelease(version) {
  if (typeof version !== 'string' || !EXACT_SEMVER_RELEASE.test(version)) return false;
  const prerelease = version.includes('-') ? version.slice(version.indexOf('-') + 1).split('.') : [];
  return !prerelease.some((identifier) => ROLLING_RELEASE_IDENTIFIERS.has(identifier.toLowerCase()));
}
