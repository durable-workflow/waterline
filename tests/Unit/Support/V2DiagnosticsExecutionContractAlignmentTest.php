<?php

namespace Waterline\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Waterline\Support\RunDiagnostics;

/**
 * Pins Waterline operator-facing run diagnostics to the v2 execution-semantics
 * and idempotency contract frozen in the workflow package at
 * docs/architecture/execution-guarantees.md.
 *
 * The contract is the single reference for duplicate-execution, retry, lease
 * expiry, and redelivery semantics across product docs, CLI reasoning,
 * Waterline diagnostics, and test coverage. When the contract renames or
 * narrows a guarantee, the {@see RunDiagnostics::GUIDANCE} strings and this
 * pinning test must be updated in the same change so operator vocabulary does
 * not drift away from the contract silently.
 *
 * Required reading before changing this test:
 * - workflow package: docs/architecture/execution-guarantees.md
 * - workflow package: tests/Feature/V2/V2DuplicateStartPolicyTest.php
 * - workflow package: tests/Feature/V2/V2WorkflowTaskBridgeTest.php
 */
final class V2DiagnosticsExecutionContractAlignmentTest extends TestCase
{
    /**
     * Every diagnostic code Waterline's RunDiagnostics can emit must carry
     * contract-aligned operator guidance. Adding a new diagnostic that has no
     * guidance string is a pinning failure; either add guidance or explicitly
     * map the code to null in {@see RunDiagnostics::GUIDANCE} if the
     * diagnostic is not a semantics teaching moment.
     */
    public function testEveryKnownDiagnosticCodeHasGuidance(): void
    {
        $expected = [
            'activity_repeated_failure',
            'activity_heartbeat_timeout_not_effective',
            'activity_unbounded_retry_policy',
            'workflow_task_repeated_failure',
            'history_budget_near_limit',
            'condition_wait_stuck',
            'no_compatible_worker_for_task',
        ];

        foreach ($expected as $code) {
            $this->assertArrayHasKey(
                $code,
                RunDiagnostics::GUIDANCE,
                sprintf('RunDiagnostics::GUIDANCE is missing contract guidance for code %s.', $code),
            );
            $this->assertNotEmpty(
                RunDiagnostics::GUIDANCE[$code],
                sprintf('Contract guidance for %s must be a non-empty string.', $code),
            );
        }
    }

    /**
     * Duplicate execution is a first-class distributed reality in the v2
     * contract, not a bug condition. Activity-retry guidance must describe
     * retries as at-least-once and direct the operator to the
     * activity_execution_id idempotency surface, which is the default
     * framework-provided idempotency key that stays stable across retries.
     */
    public function testActivityRetryGuidanceCitesAtLeastOnceAndExecutionIdSurface(): void
    {
        $guidance = RunDiagnostics::GUIDANCE['activity_repeated_failure'];

        $this->assertStringContainsString('at-least-once', $guidance);
        $this->assertStringContainsString('activity_execution_id', $guidance);
        $this->assertStringContainsString('idempotency', $guidance);
    }

    /**
     * The heartbeat-timeout diagnostic must explain that heartbeats renew the
     * activity attempt lease, and that a start_to_close timeout that fires
     * first prevents lease expiry from triggering redelivery. The contract
     * language for this surface is "lease" plus the concrete timeout fields.
     */
    public function testHeartbeatTimeoutGuidanceCitesLeaseAndTimeoutFields(): void
    {
        $guidance = RunDiagnostics::GUIDANCE['activity_heartbeat_timeout_not_effective'];

        $this->assertStringContainsString('Heartbeat', $guidance);
        $this->assertStringContainsString('lease', $guidance);
        $this->assertStringContainsString('start_to_close_timeout', $guidance);
        $this->assertStringContainsString('heartbeat_timeout', $guidance);
    }

    /**
     * Unbounded retry policies keep producing new attempts for the same
     * activity_execution_id. Contract guidance names at-least-once and the
     * execution-id surface so operators can reason about the dedupe key the
     * retries share.
     */
    public function testUnboundedRetryGuidanceCitesAtLeastOnceAndExecutionIdSurface(): void
    {
        $guidance = RunDiagnostics::GUIDANCE['activity_unbounded_retry_policy'];

        $this->assertStringContainsString('at-least-once', $guidance);
        $this->assertStringContainsString('activity_execution_id', $guidance);
        $this->assertStringContainsString('max_attempts', $guidance);
    }

    /**
     * Workflow-task failures are never duplicate application execution — the
     * workflow body is replayed deterministically. Contract guidance must
     * separate workflow-task replay from activity at-least-once so operators
     * stop reading repeated workflow-task failures as duplicate side effects.
     */
    public function testWorkflowTaskGuidanceCitesDeterministicReplay(): void
    {
        $guidance = RunDiagnostics::GUIDANCE['workflow_task_repeated_failure'];

        $this->assertStringContainsString('replay', $guidance);
        $this->assertStringContainsString('deterministic', $guidance);
        $this->assertStringContainsString('side effect', $guidance);
    }

    /**
     * The history budget diagnostic must point operators at continue-as-new
     * as a new-run primitive, not at history truncation. The contract is
     * explicit that the durable history rows are exactly-once at the durable
     * state layer.
     */
    public function testHistoryBudgetGuidanceCitesContinueAsNewAndDurableHistory(): void
    {
        $guidance = RunDiagnostics::GUIDANCE['history_budget_near_limit'];

        $this->assertStringContainsString('Continue-as-new', $guidance);
        $this->assertStringContainsString('workflow_run_id', $guidance);
        $this->assertStringContainsString('workflow_instance_id', $guidance);
        $this->assertStringContainsString('exactly-once', $guidance);
    }

    /**
     * Condition waits block the run on a durable resume source. Contract
     * guidance names the resume sources an operator should look for so the
     * "stuck" state reads as a missing signal/update/timer rather than a
     * framework bug.
     */
    public function testConditionWaitGuidanceCitesResumeSources(): void
    {
        $guidance = RunDiagnostics::GUIDANCE['condition_wait_stuck'];

        $this->assertStringContainsString('durable', $guidance);
        $this->assertStringContainsString('signal', $guidance);
        $this->assertStringContainsString('update', $guidance);
    }

    /**
     * The "no compatible worker" diagnostic is the operator-visible surface
     * for the absence-of-compatible-worker state frozen in the v2
     * worker-compatibility contract at
     * workflow@docs/architecture/worker-compatibility.md. The contract names
     * the absence as "explicit operational state, not an error", requires
     * Waterline to describe it as "no compatible worker is registered yet",
     * and requires the canonical mismatch reason to be surfaced verbatim so
     * CLI, Waterline, and cloud speak one language about mixed fleets.
     */
    public function testNoCompatibleWorkerGuidanceCitesExplicitOperationalState(): void
    {
        $guidance = RunDiagnostics::GUIDANCE['no_compatible_worker_for_task'];

        $this->assertStringContainsString('operational state', $guidance);
        $this->assertStringContainsString('not an error', $guidance);
        $this->assertStringContainsString('heartbeat', $guidance);
        $this->assertStringContainsString('compatibility', $guidance);
    }

    /**
     * Guidance strings are reviewed as part of the contract. Every guidance
     * entry must stay short (single paragraph) so it fits next to the
     * diagnostic in operator tooling.
     */
    public function testGuidanceStringsAreConciseSingleParagraphs(): void
    {
        foreach (RunDiagnostics::GUIDANCE as $code => $guidance) {
            $this->assertIsString($guidance, sprintf('Guidance for %s must be a string.', $code));
            $this->assertLessThanOrEqual(
                400,
                strlen($guidance),
                sprintf('Guidance for %s is longer than 400 characters; keep it concise.', $code),
            );
            $this->assertStringNotContainsString(
                "\n\n",
                $guidance,
                sprintf('Guidance for %s must be a single paragraph with no blank lines.', $code),
            );
        }
    }
}
