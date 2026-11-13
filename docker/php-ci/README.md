# PHP build runtime

This is Waterline's PHP 8.2 build-caller adaptation of the common runtime in
[SDK PHP #409](https://github.com/durable-workflow/sdk-php/pull/409), source
`4d37a42d1c95020fa2c75dcd0cc7585dd788f71c`. The Dockerfile, PHP trust inspection
and four vendor Brotli inputs retain that source's bytes. The image selector
is in `scripts/ci/php-container-images.json`.

The Dockerfile-specific context allowlist keeps the service image's existing
context unchanged. It includes only the common runtime inputs and the fixed
APT prerequisite. Setup requires `.central-apt-source` at the commit and helper
hash bound in `scripts/ci/setup-php-container.sh` before any build. It compiles
extensions with two jobs and uses the pinned Composer image, production INI
and no coverage. Module/function probes cover the complete common runtime,
including SQLite. Other database-driver and visual profiles remain separate.

Setup binds the inspected image config ID, creates private Composer state and
runs the real Composer TLS control before exporting the PHP/Composer launchers.
Selected CA files and empty root directories are mounted read-only. The
launcher excludes host GitHub and Composer authentication. Normal dependency
requests still require the reviewed live Composer guard.
Host-side selector and TLS-control Python run in isolated mode without site
initialization, so workspace modules and ambient import paths cannot run there.

Run the launcher boundary controls without Docker:

```sh
python3 -B -m unittest discover -s tests/ci -p test_php_container_launcher.py -v
```

Actual TLS qualification runs through setup with the built image and selected
clean CA. Keep temporary consumer/application/database fixtures under the
mounted private state, or bind the exact owned fixture. The launcher does not
mount the entire Actions temporary directory.

CI callers require their target-specific recipe admission, independent review
and unchanged functional qualification before migration. Local image/caller
evidence does not substitute for those gates or qualify other PHP minors.
