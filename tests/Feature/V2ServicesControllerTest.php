<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

class V2ServicesControllerTest extends TestCase
{
    public function testEndpointsIndexFiltersByConfiguredNamespace(): void
    {
        config()->set('waterline.namespace', 'billing');

        $billing = $this->createEndpoint('billing', 'invoices');
        $shipping = $this->createEndpoint('shipping', 'invoices');

        $response = $this->getJson('/waterline/api/v2/services/endpoints')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($billing->id, $ids);
        $this->assertNotContains($shipping->id, $ids);
    }

    public function testEndpointShowReturnsNotFoundForOutOfNamespaceEndpoint(): void
    {
        config()->set('waterline.namespace', 'billing');

        $shipping = $this->createEndpoint('shipping', 'invoices');

        $this->getJson('/waterline/api/v2/services/endpoints/'.$shipping->id)
            ->assertNotFound()
            ->assertJsonPath('error', 'Service endpoint not found.');
    }

    public function testEndpointShowReturnsDetailWithNestedServicesAndOperations(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $this->createService($endpoint, 'shipping', 'foreign-inbox');
        $this->createOperation($endpoint, $service, 'billing', 'create');
        $this->createOperation($endpoint, $service, 'shipping', 'foreign-create');

        $this->getJson('/waterline/api/v2/services/endpoints/'.$endpoint->id)
            ->assertOk()
            ->assertJsonPath('id', $endpoint->id)
            ->assertJsonPath('endpoint_name', 'invoices')
            ->assertJsonPath('services.0.service_name', 'inbox')
            ->assertJsonCount(1, 'services')
            ->assertJsonPath('operations.0.operation_name', 'create')
            ->assertJsonPath('operations.0.handler_binding_kind', 'start_workflow')
            ->assertJsonCount(1, 'operations');
    }

    public function testServicesIndexFiltersByEndpointAndNamespace(): void
    {
        config()->set('waterline.namespace', 'billing');

        $billingEndpoint = $this->createEndpoint('billing', 'invoices');
        $billingService = $this->createService($billingEndpoint, 'billing', 'inbox');

        $shippingEndpoint = $this->createEndpoint('shipping', 'invoices');
        $this->createService($shippingEndpoint, 'shipping', 'inbox');

        $response = $this->getJson('/waterline/api/v2/services/services?endpoint_id='.$billingEndpoint->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($billingService->id, $response->json('data.0.id'));
    }

    public function testServiceShowHidesForeignEndpointAndOperationsFromDetail(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $this->createOperation($endpoint, $service, 'shipping', 'foreign-create');

        $this->getJson('/waterline/api/v2/services/services/'.$service->id)
            ->assertOk()
            ->assertJsonPath('endpoint', null)
            ->assertJsonCount(0, 'operations');
    }

    public function testOperationShowReturnsNotFoundForOutOfNamespaceOperation(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'shipping', 'create');

        $this->getJson('/waterline/api/v2/services/operations/'.$operation->id)
            ->assertNotFound()
            ->assertJsonPath('error', 'Service operation not found.');
    }

    public function testOperationShowHidesForeignEndpointAndServiceFromDetail(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $this->getJson('/waterline/api/v2/services/operations/'.$operation->id)
            ->assertOk()
            ->assertJsonPath('endpoint', null)
            ->assertJsonPath('service', null);
    }

    public function testServiceCallsIndexShowsCallsOwnedByConfiguredNamespace(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $billingCall = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'caller_namespace' => 'billing',
            'target_namespace' => 'billing',
            'status' => ServiceCallStatus::Completed->value,
        ]);

        $shippingEndpoint = $this->createEndpoint('shipping', 'invoices');
        $shippingService = $this->createService($shippingEndpoint, 'shipping', 'inbox');
        $shippingOperation = $this->createOperation($shippingEndpoint, $shippingService, 'shipping', 'create');

        $shippingCall = $this->createServiceCall($shippingEndpoint, $shippingService, $shippingOperation, [
            'namespace' => 'shipping',
            'caller_namespace' => 'shipping',
            'target_namespace' => 'shipping',
            'status' => ServiceCallStatus::Completed->value,
        ]);

        $response = $this->getJson('/waterline/api/v2/services/calls')
            ->assertOk()
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('scope', 'relevant')
            ->assertJsonCount(1, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($billingCall->id, $ids);
        $this->assertNotContains($shippingCall->id, $ids);
    }

    public function testServiceCallsIndexDefaultScopeIncludesCallsInitiatedFromConfiguredNamespace(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'shipping', 'create');

        $crossCall = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'shipping',
            'caller_namespace' => 'billing',
            'target_namespace' => 'shipping',
            'status' => ServiceCallStatus::Started->value,
        ]);

        $this->getJson('/waterline/api/v2/services/calls')
            ->assertOk()
            ->assertJsonPath('scope', 'relevant')
            ->assertJsonPath('data.0.id', $crossCall->id);
    }

    public function testServiceCallsIndexCallerScopeShowsCrossNamespaceCallsInitiatedFromNamespace(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'shipping', 'create');

        $crossCall = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'shipping',
            'caller_namespace' => 'billing',
            'target_namespace' => 'shipping',
            'status' => ServiceCallStatus::Started->value,
        ]);

        $this->getJson('/waterline/api/v2/services/calls?scope=owned')
            ->assertOk()
            ->assertJsonPath('scope', 'owned')
            ->assertJsonCount(0, 'data');

        $this->getJson('/waterline/api/v2/services/calls?scope=caller')
            ->assertOk()
            ->assertJsonPath('scope', 'caller')
            ->assertJsonPath('data.0.id', $crossCall->id);
    }

    public function testServiceCallsIndexFiltersByStatusAndExposesBuckets(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $blocked = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::HandlerFailed->value,
        ]);
        $accepted = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Accepted->value,
        ]);

        $response = $this->getJson('/waterline/api/v2/services/calls?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $blocked->id)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.status_bucket', 'failed')
            ->assertJsonPath('data.0.outcome_bucket', 'failed');

        $this->assertNotContains($accepted->id, collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(['failed'], $response->json('status_buckets.failed'));
    }

    public function testServiceCallsIndexBucketFilterCollapsesToConfiguredStatuses(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $blocked = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
        ]);
        $cancelled = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Cancelled->value,
        ]);
        $accepted = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Accepted->value,
        ]);

        $response = $this->getJson('/waterline/api/v2/services/calls?status_bucket=failed')
            ->assertOk()
            ->assertJsonPath('status_bucket', 'failed')
            ->assertJsonCount(1, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$blocked->id], $ids);
        $this->assertNotContains($cancelled->id, $ids);
        $this->assertNotContains($accepted->id, $ids);
    }

    public function testServiceCallsIndexFiltersByOutcomeAndExposesOutcomeBuckets(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $policy = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::RejectedForbidden->value,
        ]);
        $handlerFailure = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::HandlerFailed->value,
        ]);

        $response = $this->getJson('/waterline/api/v2/services/calls?outcome=rejected_forbidden')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('outcome', 'rejected_forbidden')
            ->assertJsonPath('data.0.id', $policy->id)
            ->assertJsonPath('data.0.outcome_bucket', 'policy')
            ->assertJsonPath('data.0.is_policy_outcome', true);

        $this->assertNotContains($handlerFailure->id, collect($response->json('data'))->pluck('id')->all());
        $this->assertContains('rejected_forbidden', $response->json('outcome_buckets.policy'));
    }

    public function testServiceCallsIndexOutcomeBucketFilterCollapsesToPolicyOutcomes(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $policy = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::RejectedForbidden->value,
        ]);
        $throttled = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::RejectedThrottled->value,
        ]);
        $handlerFailure = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::HandlerFailed->value,
        ]);

        $response = $this->getJson('/waterline/api/v2/services/calls?outcome_bucket=policy')
            ->assertOk()
            ->assertJsonPath('outcome_bucket', 'policy')
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$policy->id, $throttled->id], $ids);
        $this->assertNotContains($handlerFailure->id, $ids);
    }

    public function testServiceCallShowReturns404WhenObserverNamespaceDoesNotMatchAnySide(): void
    {
        config()->set('waterline.namespace', 'finance');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $call = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'caller_namespace' => 'shipping',
            'target_namespace' => 'billing',
            'status' => ServiceCallStatus::Completed->value,
        ]);

        $this->getJson('/waterline/api/v2/services/calls/'.$call->id)
            ->assertNotFound()
            ->assertJsonPath('error', 'Service call not found.');
    }

    public function testServiceCallShowFlagsForeignLinkedRunRefSoOperatorsCannotBrowseThrough(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $call = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'caller_namespace' => 'billing',
            'caller_workflow_instance_id' => 'inst-billing-1',
            'caller_workflow_run_id' => 'run-billing-1',
            'target_namespace' => 'shipping',
            'linked_workflow_instance_id' => 'inst-shipping-1',
            'linked_workflow_run_id' => 'run-shipping-1',
            'linked_workflow_update_id' => 'upd-shipping-1',
            'status' => ServiceCallStatus::Started->value,
        ]);

        $this->getJson('/waterline/api/v2/services/calls/'.$call->id)
            ->assertOk()
            ->assertJsonPath('id', $call->id)
            ->assertJsonPath('caller_link.namespace', 'billing')
            ->assertJsonPath('caller_link.in_observer_namespace', true)
            ->assertJsonPath('linked_run_ref.namespace', 'shipping')
            ->assertJsonPath('linked_run_ref.in_observer_namespace', false)
            ->assertJsonPath('linked_update_ref.namespace', 'shipping')
            ->assertJsonPath('linked_update_ref.in_observer_namespace', false);
    }

    public function testInvalidScopeQueryParameterFallsBackToRelevant(): void
    {
        config()->set('waterline.namespace', 'billing');

        $this->getJson('/waterline/api/v2/services/calls?scope=anything-else')
            ->assertOk()
            ->assertJsonPath('scope', 'relevant');
    }

    public function testInvalidStatusQueryParameterIsIgnored(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');
        $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Accepted->value,
        ]);

        $this->getJson('/waterline/api/v2/services/calls?status=garbage')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function testInvalidOutcomeQueryParameterIsIgnored(): void
    {
        config()->set('waterline.namespace', 'billing');

        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');
        $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::HandlerFailed->value,
        ]);

        $this->getJson('/waterline/api/v2/services/calls?outcome=garbage')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function createEndpoint(string $namespace, string $name): WorkflowServiceEndpoint
    {
        return WorkflowServiceEndpoint::create([
            'namespace' => $namespace,
            'endpoint_name' => $name,
        ]);
    }

    private function createService(WorkflowServiceEndpoint $endpoint, string $namespace, string $name): WorkflowService
    {
        return WorkflowService::create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'namespace' => $namespace,
            'service_name' => $name,
        ]);
    }

    private function createOperation(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        string $namespace,
        string $name,
    ): WorkflowServiceOperation {
        return WorkflowServiceOperation::create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'namespace' => $namespace,
            'operation_name' => $name,
            'operation_mode' => 'request_reply',
            'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'Tests\\HandlerWorkflow',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createServiceCall(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
        array $overrides,
    ): WorkflowServiceCall {
        $defaults = [
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'operation_name' => $operation->operation_name,
            'operation_mode' => $operation->operation_mode,
            'resolved_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
        ];

        return WorkflowServiceCall::create(array_merge($defaults, $overrides));
    }
}
