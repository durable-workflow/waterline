#!/usr/bin/env node
// Judge an `npm audit --json` report: fail on any advisory at or above
// moderate severity, except an exact reviewed (advisory, package) pair with no
// patched release. Transitive entries only name packages; every root advisory
// must be allowlisted for the audit to pass. A missing, malformed or errored
// report fails, so a failed `npm audit` invocation can never pass.
//
// Usage: npm audit --json > report.json || true
//        node scripts/ci/npm-audit-allowlisted.mjs < report.json
import { readFileSync } from 'node:fs';

const ALLOWED = new Map([
  // picomatch ReDoS via single-branch repeated extglobs. No patched release
  // exists (latest 4.0.7 is affected). Reached only through dev-only build
  // tooling (Vite, @parcel/watcher, tinyglobby) evaluating repository-owned
  // globs; it is not in the shipped assets, so the pattern input is never
  // attacker-controlled. Remove when a fix ships.
  ['https://github.com/advisories/GHSA-fc5q-39xc-vjpf', 'picomatch'],
]);
const FAILING = new Set(['moderate', 'high', 'critical']);

export function evaluate(report) {
  if (report?.error || typeof report?.metadata?.vulnerabilities !== 'object') {
    return { failures: [`npm audit did not produce a report: ${JSON.stringify(report?.error ?? 'no metadata')}`], accepted: [] };
  }
  const failures = [];
  const accepted = [];
  for (const [name, vulnerability] of Object.entries(report.vulnerabilities ?? {})) {
    for (const via of vulnerability.via ?? []) {
      if (typeof via === 'string') continue; // transitive: resolved through its own entry
      const url = via.url ?? String(via.source);
      if (!FAILING.has(via.severity)) continue;
      if (ALLOWED.get(url) === name) accepted.push(`${name} ${url}`);
      else failures.push(`${name} ${via.severity} ${url} ${via.title ?? ''}`.trim());
    }
  }
  return { failures, accepted: [...new Set(accepted)] };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  let report;
  try {
    report = JSON.parse(readFileSync(0, 'utf8')); // the report on stdin
  } catch (error) {
    console.log(`::error::cannot read npm audit report: ${error.message}`);
    process.exit(1);
  }
  const { failures, accepted } = evaluate(report);
  for (const item of accepted) console.log(`::warning::accepted reviewed advisory: ${item}`);
  for (const item of failures) console.log(`::error::${item}`);
  if (failures.length) process.exit(1);
  console.log(`npm audit: no unreviewed moderate or higher advisories (${accepted.length} accepted).`);
}
