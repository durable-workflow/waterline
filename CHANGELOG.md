# Changelog

## Unreleased

Waterline keeps the Durable Workflow 2.0 conformance claim aligned to
platform conformance suite version 17 and the current published artifact
tuple: server 0.2.238, CLI 0.1.75, Workflow 2.0.0-alpha.189, Python SDK
0.4.84, and Waterline 2.0.0-alpha.76. The focused
`waterline:principal-attribution-conformance` shard now documents
operator-visible command and timeline principal attribution alongside the
namespace and search-attribute Waterline shards. Migration runtime and
skew refusal matrix evidence remain load-bearing Waterline release
categories.

The package now exports a focused published-artifact worker-status runner.
It compares live Waterline list and task-queue worker projections with the
standalone server API and CLI through two heartbeats, real workflow work, and
a bounded stale transition. Published-port readiness requests have per-attempt
timeouts and retries, and all outcomes remove the package host plus labeled
Compose containers, volumes, networks, and scratch state. Published observer
hosts can align Waterline's freshness classification with the server through
`WATERLINE_WORKER_STALE_AFTER_SECONDS`.
