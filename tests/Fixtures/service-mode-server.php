<?php

declare(strict_types=1);

$headers = getallheaders();
if (($headers['Authorization'] ?? '') !== 'Bearer smoke-token'
    || ($headers['X-Namespace'] ?? '') !== 'smoke'
    || ($headers['X-Durable-Workflow-Control-Plane-Version'] ?? '') !== '2') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Smoke credentials were not forwarded.', 'reason' => 'authorization_failed']);

    return;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $query);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestBody = json_decode((string) file_get_contents('php://input'), true);
error_log($method.' '.$path);
$payload = match (true) {
    $method === 'GET' && $path === '/api/workflows' => [
        'workflows' => [[
            'workflow_id' => 'smoke-order',
            'run_id' => 'smoke-run',
            'workflow_type' => 'smoke.order',
            'namespace' => 'smoke',
            'task_queue' => 'smoke',
            'status' => 'running',
            'status_bucket' => 'running',
            'is_terminal' => false,
            'started_at' => '2026-07-22T12:00:00Z',
        ]],
        'workflow_count' => 1,
        'next_page_token' => null,
    ],
    $method === 'GET' && in_array($path, [
        '/api/workflows/smoke-order',
        '/api/workflows/smoke-order/runs/smoke-run',
    ], true) => [
        'workflow_id' => 'smoke-order',
        'run_id' => 'smoke-run',
        'workflow_type' => 'smoke.order',
        'namespace' => 'smoke',
        'task_queue' => 'smoke',
        'status' => 'running',
        'status_bucket' => 'running',
        'is_terminal' => false,
        'actions' => ['can_query' => true, 'can_signal' => true],
    ],
    $method === 'GET' && $path === '/api/workflows/smoke-order/runs' => [
        'runs' => [[
            'workflow_id' => 'smoke-order',
            'run_id' => 'smoke-run',
            'workflow_type' => 'smoke.order',
            'status' => 'running',
            'is_current_run' => true,
        ]],
    ],
    $method === 'GET' && $path === '/api/workflows/smoke-order/runs/smoke-run/history' => [
        'events' => [[
            'id' => 'event-1',
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
        ]],
    ],
    $method === 'GET' && $path === '/api/workflows/smoke-order/runs/smoke-run/debug' => ['tasks' => []],
    $method === 'POST' && $path === '/api/workflows/smoke-order/runs/smoke-run/query/current' => [
        'result' => [
            'state' => 'awaiting_approval',
            'selected_run_id' => 'smoke-run',
        ],
    ],
    $method === 'POST' && $path === '/api/workflows/smoke-order/runs/smoke-run/signal/approve' => [
        'command_status' => 'accepted',
        'signal_name' => 'approve',
        'selected_run_id' => 'smoke-run',
        'input_received' => is_array($requestBody['input'] ?? null),
    ],
    $method === 'GET' && $path === '/api/system/health' => ['health' => ['status' => 'healthy', 'checks' => []]],
    $method === 'GET' && $path === '/api/system/operator-metrics' => ['operator_metrics' => ['runs' => ['total' => 1, 'running' => 1]]],
    $method === 'GET' && $path === '/api/system/operator-dashboard' => ['dashboard' => [
        'flows' => 1,
        'flows_past_hour' => 1,
        'operator_metrics' => ['runs' => ['total' => 1, 'running' => 1]],
        'fleet_overview' => ['current' => ['running' => 1, 'failed' => 0]],
        'needs_attention' => ['total_alerts' => 0, 'has_critical' => false, 'alerts' => []],
        'workflow_type_health' => [],
        'fleet_trends_series' => null,
    ]],
    $method === 'GET' && $path === '/api/workers' => ['workers' => ($query['status'] ?? null) === 'stale' ? [] : [[
        'worker_id' => 'smoke-worker',
        'namespace' => 'smoke',
        'task_queue' => 'smoke',
        'status' => 'active',
    ]]],
    $method === 'GET' && $path === '/api/task-queues' => ['namespace' => 'smoke', 'task_queues' => [[
        'name' => 'smoke',
        'stats' => ['approximate_backlog_count' => 0],
    ]]],
    $method === 'GET' && $path === '/api/schedules' => ['schedules' => [], 'next_page_token' => null],
    default => ['message' => 'Route not found.', 'reason' => 'not_found', 'path' => $path],
};

if (($payload['reason'] ?? null) === 'not_found') {
    http_response_code(404);
}

header('Content-Type: application/json');
echo json_encode($payload, JSON_THROW_ON_ERROR);
