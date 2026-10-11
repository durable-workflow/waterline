<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use DurableWorkflow\Exception\ServerException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Waterline\Support\Remote\RemotePayloadPreview;

final class RemoteServicesController extends RemoteController
{
    public function endpointsIndex(Request $request): JsonResponse
    {
        return $this->index($request, 'listServiceCatalogEndpoints');
    }

    public function endpointShow(Request $request, string $endpointId): JsonResponse
    {
        return $this->detail($request, 'describeServiceCatalogEndpoint', $endpointId);
    }

    public function servicesIndex(Request $request): JsonResponse
    {
        return $this->index($request, 'listServiceCatalogServices');
    }

    public function serviceShow(Request $request, string $serviceId): JsonResponse
    {
        return $this->detail($request, 'describeServiceCatalogService', $serviceId);
    }

    public function operationsIndex(Request $request): JsonResponse
    {
        return $this->index($request, 'listServiceCatalogOperations');
    }

    public function operationShow(Request $request, string $operationId): JsonResponse
    {
        return $this->detail($request, 'describeServiceCatalogOperation', $operationId);
    }

    public function callsIndex(Request $request): JsonResponse
    {
        return $this->index($request, 'listServiceCatalogCalls');
    }

    public function callShow(Request $request, string $callId): JsonResponse
    {
        return $this->detail($request, 'describeServiceCatalogCall', $callId);
    }

    private function index(Request $request, string $method): JsonResponse
    {
        if (array_key_exists('namespace', $request->query())) {
            throw ValidationException::withMessages([
                'namespace' => 'The observer namespace is set by Waterline configuration.',
            ]);
        }

        return $this->read($method, [$request->query()]);
    }

    private function detail(Request $request, string $method, string $id): JsonResponse
    {
        if ($request->query() !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                array_keys($request->query()),
                'Catalog detail does not accept query parameters.',
            ));
        }

        return $this->read($method, [$id]);
    }

    private function read(string $method, array $arguments): JsonResponse
    {
        if ($response = $this->requireCapability($method, 'services')) {
            return $response;
        }

        try {
            $payload = $this->backend->serviceCatalogRead($method, $arguments);
        } catch (ServerException $exception) {
            if ($exception->status !== 501
                || $exception->reason !== 'backend_capability_unavailable'
                || ($exception->details['capability'] ?? null) !== 'service_catalog_reads') {
                throw $exception;
            }

            return response()->json($this->scoped([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
                'capability' => 'services',
                'required_sdk_method' => $method,
                'required_contract' => $exception->details['required_contract'] ?? null,
                'required_contract_version' => $exception->details['required_contract_version'] ?? null,
                'advertised_contract' => $exception->details['advertised_contract'] ?? null,
            ]), 501);
        }

        if ($method === 'describeServiceCatalogCall' && ($payload['output_available'] ?? null) === true) {
            $payload['output'] = RemotePayloadPreview::display($payload['output'] ?? null);
            unset($payload['output_envelope'], $payload['result_envelope'], $payload['metadata']['nexus_result']);
        }

        return response()->json($this->scoped($payload), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
