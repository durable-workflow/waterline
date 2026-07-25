#!/usr/bin/env node

import { appendFileSync, writeFileSync } from 'node:fs';

const startedAt = positiveInteger('BUILD_STARTED_AT');
const finishedAt = optionalPositiveInteger('BUILD_FINISHED_AT') ?? Math.floor(Date.now() / 1000);
const baselineFloorSeconds = positiveInteger('UNCACHED_BASELINE_FLOOR_SECONDS');
const warmCacheTargetSeconds = positiveInteger('WARM_CACHE_TARGET_SECONDS');
const buildOutcome = required('BUILD_OUTCOME');
const cacheRef = required('CACHE_REF');
const cacheState = oneOf('CACHE_STATE', ['cold', 'warm']);
const releaseTag = required('RELEASE_TAG');
const sourceCommit = required('SOURCE_COMMIT');
const platforms = required('SERVICE_IMAGE_PLATFORMS')
  .split(',')
  .map((platform) => platform.trim())
  .filter(Boolean);
const imageDigest = process.env.IMAGE_DIGEST?.trim() ?? '';
const evidenceFile = process.env.BUILD_TIMING_EVIDENCE_FILE?.trim()
  || 'service-image-build-evidence.json';

if (finishedAt < startedAt) {
  throw new Error('BUILD_FINISHED_AT must not precede BUILD_STARTED_AT.');
}
if (!/^[0-9a-f]{40}$/u.test(sourceCommit)) {
  throw new Error('SOURCE_COMMIT must be the exact lowercase release commit.');
}
if (platforms.length === 0) {
  throw new Error('SERVICE_IMAGE_PLATFORMS must select at least one platform.');
}
if (imageDigest !== '' && !/^sha256:[0-9a-f]{64}$/u.test(imageDigest)) {
  throw new Error('IMAGE_DIGEST must be an sha256 digest when provided.');
}

const durationSeconds = finishedAt - startedAt;
const measuredWarmBuild = cacheState === 'warm' && buildOutcome === 'success';
const meetsRepeatBudget = measuredWarmBuild
  ? durationSeconds <= warmCacheTargetSeconds
  : null;
const minimumImprovementSeconds = measuredWarmBuild && durationSeconds < baselineFloorSeconds
  ? baselineFloorSeconds - durationSeconds
  : null;
const minimumImprovementPercent = minimumImprovementSeconds === null
  ? null
  : Number(((minimumImprovementSeconds / baselineFloorSeconds) * 100).toFixed(1));

const evidence = {
  schema: 'durable-workflow.waterline.service-image-build.v1',
  release_tag: releaseTag,
  source_commit: sourceCommit,
  image_digest: imageDigest || null,
  platforms,
  build_outcome: buildOutcome,
  cache: {
    ref: cacheRef,
    state: cacheState,
    write_scope: 'protected-tag-publication',
  },
  timing: {
    uncached_baseline: {
      relation: 'greater_than',
      seconds: baselineFloorSeconds,
    },
    build_seconds: durationSeconds,
    warm_cache_target_seconds: warmCacheTargetSeconds,
    meets_repeat_budget: meetsRepeatBudget,
    minimum_improvement_seconds: minimumImprovementSeconds,
    minimum_improvement_percent: minimumImprovementPercent,
  },
};

writeFileSync(evidenceFile, `${JSON.stringify(evidence, null, 2)}\n`);

if (process.env.GITHUB_OUTPUT) {
  appendFileSync(
    process.env.GITHUB_OUTPUT,
    [
      `duration_seconds=${durationSeconds}`,
      `meets_repeat_budget=${meetsRepeatBudget ?? 'not-applicable'}`,
      `evidence_file=${evidenceFile}`,
      '',
    ].join('\n'),
  );
}

if (process.env.GITHUB_STEP_SUMMARY) {
  const budgetResult = meetsRepeatBudget === null
    ? 'not evaluated until the protected cache is warm'
    : meetsRepeatBudget ? 'passed' : 'failed';
  const improvement = minimumImprovementSeconds === null
    ? 'not yet demonstrated'
    : `at least ${minimumImprovementSeconds}s (${minimumImprovementPercent}%)`;

  appendFileSync(
    process.env.GITHUB_STEP_SUMMARY,
    [
      '### Protected Waterline service image build',
      '',
      `- Uncached beta.5 baseline: more than ${baselineFloorSeconds}s`,
      `- Current ${cacheState}-cache build: ${durationSeconds}s`,
      `- Minimum measured improvement: ${improvement}`,
      `- Warm-cache ${warmCacheTargetSeconds}s budget: ${budgetResult}`,
      `- Cache: \`${cacheRef}\``,
      '',
    ].join('\n'),
  );
}

function required(name) {
  const value = process.env[name]?.trim() ?? '';
  if (value === '') {
    throw new Error(`${name} is required.`);
  }

  return value;
}

function positiveInteger(name) {
  const value = required(name);
  if (!/^[1-9][0-9]*$/u.test(value)) {
    throw new Error(`${name} must be a positive integer.`);
  }

  return Number(value);
}

function optionalPositiveInteger(name) {
  const value = process.env[name]?.trim() ?? '';
  if (value === '') {
    return null;
  }
  if (!/^[1-9][0-9]*$/u.test(value)) {
    throw new Error(`${name} must be a positive integer when provided.`);
  }

  return Number(value);
}

function oneOf(name, choices) {
  const value = required(name);
  if (!choices.includes(value)) {
    throw new Error(`${name} must be one of: ${choices.join(', ')}.`);
  }

  return value;
}
