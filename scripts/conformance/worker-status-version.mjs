const EXACT_2X_PRERELEASE = /^2\.0\.0-(?:alpha|beta|rc)\.(?:0|[1-9]\d*)$/;

export function isExact2xPrerelease(version) {
  return EXACT_2X_PRERELEASE.test(version);
}
