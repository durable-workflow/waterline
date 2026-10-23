# Published SDK 3.4 service consumer

On 2026-10-23, an isolated Laravel service-mode consumer installed ordinary
Composer distributions with no custom repository, source override or package
patch:

- Waterline **2.13.0**, source/dist `30ec59eac270dafd3025afd65175670c6a0f7ab6`.
- PHP SDK **3.4.0**, source/dist `1bf25b5848f2031e72a3da38794b51db1d533546`.
- Laravel **12.70.1**, PHP **8.3.36**, Guzzle **7.16.0**, PSR HTTP message **2.0**.

The test dispatches actual requests through Laravel's HTTP kernel and installed
Waterline routes/middleware. The installed SDK makes real loopback HTTP requests
to a separate synthetic server. Embedded Core is absent. This qualifies the
Laravel-hosted service adapter; it does not replace the SDK inside Waterline's
published service image.

| Selection | Result | Elapsed |
| --- | --- | --- |
| Unmodified `RemoteBackend::fromConfig`, healthy server | HTTP 200, two workflows, namespace `smoke` and read-only authority | 0.262 s |
| Unmodified `RemoteBackend::fromConfig`, server sends no headers/body | HTTP 502, `remote_transport_failure`, `request_timeout` | 60.162 s |
| Application-injected SDK client with `requestTimeoutSeconds: 0.2`, separate stalled server | Same typed HTTP 502 timeout | 0.324 s |

Each stalled server recorded exactly one request. No mutation or retry was
submitted. The default path uses the SDK's owned 60-second policy. The bounded
control creates a normal SDK client and injects `RemoteBackend` through the host
container; it does **not** show that Waterline passes a configurable timeout.
Waterline's resolved service configuration still contains only endpoint, token,
namespace and access mode.

This is API integration acceptance. Browser timeout and run-list Retry behavior
were not exercised by this probe. Source inspection of 2.13.0 still finds no
global Axios timeout and no Retry button in the list error alert. Configurable
Waterline timeout and those browser behaviors remain under
[SDK #203](https://github.com/durable-workflow/sdk-php/issues/203). This receipt
does not close that issue or change a package floor, optional suggestion,
development pin or service-image dependency.

## Reproduce

Use an isolated directory with PHP 8.3, PDO SQLite, cURL and Composer 2.10.3 in
Docker. Require exact `durable-workflow/waterline:2.13.0`,
`durable-workflow/sdk:3.4.0` and `laravel/framework:12.70.1`, using ordinary
Composer dist installation. Do not install embedded Core.

Copy `scripts/investigations/sdk34-consumer-host.php` to `host.php`,
`sdk34-stall-server.php` to `stall.php`, and
`tests/Fixtures/service-mode-server.php` to `healthy.php` in that directory.
Create `bootstrap/cache`, `storage/framework/views`, `storage/logs` and an empty
`database.sqlite`. Start separate loopback PHP servers:

```sh
php -S 127.0.0.1:8081 healthy.php
php -S 127.0.0.1:8082 stall.php
php -S 127.0.0.1:8083 stall.php
```

Then run each probe in a fresh PHP process:

```sh
php host.php healthy > healthy.json
php host.php default-stall > default-stall.json
php host.php injected-stall > injected-stall.json
```

The default case intentionally takes about one minute. Keep all listeners and
application data isolated from production, retain the JSON and Composer lock,
then stop the three task-owned servers and remove the temporary consumer.
