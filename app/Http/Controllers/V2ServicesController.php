<?php

namespace Waterline\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Support\ServiceCallView;
use Workflow\V2\Support\ServiceCatalog;
use Workflow\V2\Support\ServiceEndpointView;
use Workflow\V2\Support\ServiceOperationView;
use Workflow\V2\Support\ServiceView;

/**
 * Namespace-scoped operator views for the v2 cross-namespace service catalog.
 *
 * Mirrors V2SchedulesController in shape and not-found semantics: when
 * `waterline.namespace` is configured, list views only contain catalog
 * objects for that namespace, and detail lookups return 404 for objects
 * owned by another namespace. Service calls additionally honor caller and
 * target namespace when matching, so an operator sees calls relevant to
 * their namespace from either side without becoming a cross-namespace
 * browser.
 */
class V2ServicesController extends Controller
{
    public function endpointsIndex(Request $request): JsonResponse
    {
        $page = ServiceCatalog::endpointsQuery($this->namespace())->paginate(50);

        return $this->paginated($page, static fn ($endpoint): array => ServiceEndpointView::listItem($endpoint));
    }

    public function endpointShow(string $endpointId): JsonResponse
    {
        $endpoint = ServiceCatalog::findEndpoint($endpointId, $this->namespace());

        if ($endpoint === null) {
            return $this->notFound('Service endpoint');
        }

        return response()->json(ServiceEndpointView::detail($endpoint));
    }

    public function servicesIndex(Request $request): JsonResponse
    {
        $query = ServiceCatalog::servicesQuery($this->namespace());

        $endpointId = $request->query('endpoint_id');
        if (is_string($endpointId) && $endpointId !== '') {
            $query->where('workflow_service_endpoint_id', $endpointId);
        }

        $page = $query->paginate(50);

        return $this->paginated($page, static fn ($service): array => ServiceView::listItem($service));
    }

    public function serviceShow(string $serviceId): JsonResponse
    {
        $service = ServiceCatalog::findService($serviceId, $this->namespace());

        if ($service === null) {
            return $this->notFound('Service');
        }

        return response()->json(ServiceView::detail($service));
    }

    public function operationsIndex(Request $request): JsonResponse
    {
        $query = ServiceCatalog::operationsQuery($this->namespace());

        $serviceId = $request->query('service_id');
        if (is_string($serviceId) && $serviceId !== '') {
            $query->where('workflow_service_id', $serviceId);
        }

        $endpointId = $request->query('endpoint_id');
        if (is_string($endpointId) && $endpointId !== '') {
            $query->where('workflow_service_endpoint_id', $endpointId);
        }

        $page = $query->paginate(50);

        return $this->paginated($page, static fn ($operation): array => ServiceOperationView::listItem($operation));
    }

    public function operationShow(string $operationId): JsonResponse
    {
        $operation = ServiceCatalog::findOperation($operationId, $this->namespace());

        if ($operation === null) {
            return $this->notFound('Service operation');
        }

        return response()->json(ServiceOperationView::detail($operation));
    }

    public function callsIndex(Request $request): JsonResponse
    {
        $scope = $this->parseScope($request->query('scope'));
        $status = $this->parseStatus($request->query('status'));
        $bucket = $this->parseBucket($request->query('bucket'));

        $query = ServiceCatalog::serviceCallsQuery($this->namespace(), $scope, $status);

        if ($bucket !== null && $status === null) {
            $query->whereIn('status', ServiceCallStatus::buckets()[$bucket]);
        }

        $endpointId = $request->query('endpoint_id');
        if (is_string($endpointId) && $endpointId !== '') {
            $query->where('workflow_service_endpoint_id', $endpointId);
        }

        $serviceId = $request->query('service_id');
        if (is_string($serviceId) && $serviceId !== '') {
            $query->where('workflow_service_id', $serviceId);
        }

        $operationId = $request->query('operation_id');
        if (is_string($operationId) && $operationId !== '') {
            $query->where('workflow_service_operation_id', $operationId);
        }

        $page = $query->paginate(50);

        return $this->paginated(
            $page,
            static fn ($call): array => ServiceCallView::listItem($call),
            [
                'scope' => $scope,
                'bucket' => $bucket,
                'namespace' => $this->namespace(),
                'status_buckets' => ServiceCallStatus::buckets(),
            ],
        );
    }

    public function callShow(string $callId): JsonResponse
    {
        $call = ServiceCatalog::findServiceCall($callId, $this->namespace());

        if ($call === null) {
            return $this->notFound('Service call');
        }

        return response()->json(ServiceCallView::detail($call, $this->namespace()));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function paginated(\Illuminate\Contracts\Pagination\LengthAwarePaginator $page, callable $shape, array $extra = []): JsonResponse
    {
        $items = collect($page->items())->map(static fn ($model): array => $shape($model))->values()->all();

        return response()->json(array_merge([
            'data' => $items,
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ], $extra));
    }

    private function parseScope(mixed $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($value, ServiceCatalog::SCOPES, true)
            ? $value
            : ServiceCatalog::SCOPE_OWNED;
    }

    private function parseStatus(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $candidate = trim($raw);

        return ServiceCallStatus::tryFrom($candidate)?->value;
    }

    private function parseBucket(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $candidate = strtolower(trim($raw));
        $buckets = ServiceCallStatus::buckets();

        return array_key_exists($candidate, $buckets) ? $candidate : null;
    }

    private function notFound(string $what): JsonResponse
    {
        return response()->json(['error' => $what.' not found.'], 404);
    }

    private function namespace(): ?string
    {
        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }
}
