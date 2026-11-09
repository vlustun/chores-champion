# Build Plan

> I can not run composer install on the app container : www-data@3ffa42b943cf:/app$ make install composer install Installing dependencies from lock file (including require-dev) Verifying lock file contents can be installed on current platform. Cannot create cache directory /var/www/.composer/cache/repo/https---repo.packagist.org/, or directory is not writable. Proceeding without cache. See also cache-read-only config if your filesystem is read-only. Package operations: 57 installs, 0 updates, 0 removals     Failed to download composer/pcre from dist: /app/vendor/composer/pcre does not exist and could not be created:  In Filesystem.php line 267:    /app/vendor/composer/pcre does not exist and could not be created:

**Stack:** Docker Compose (php:8.3-fpm `fpm-dev` target, `cli` tools image), Composer 2, Make, Symfony. Run with `docker compose -f docker-compose.yml -f compose.xdebug.yaml up -d --build app`.

## Files

- `compose.xdebug.yaml` — Run the dev `app` service as the host UID/GID. Set COMPOSER_HOME=/tmp/composer and HOME=/tmp so the bind-mounted /app/vendor and the Composer cache are writable.
- `docker/php/Dockerfile` — In `fpm-dev`, set `ENV COMPOSER_HOME=/tmp/composer` and create a world-writable cache dir. Drop the hard-coded `USER www-data`, or keep it as the default and rely on the compose `user:` override.
- `Makefile` — Make `install` and the other PHP tooling default to the `php` tools service, which already runs as ${UID}:${GID}. Alternatively add an `install-app` target that runs `docker compose exec -u $(id -u):$(id -g) app composer install`.
- `README.md` — Document the permission cause and the correct command for installing dependencies in the container. Document `export UID GID` for the compose `user:` setting.
- `.env.docker.example` — Add UID=1000 and GID=1000 placeholders, since bash does not export UID by default.

## Tasks

- [x] Confirm the cause: run `docker compose exec app id` (expect uid=33 www-data) and `ls -ld vendor` on the host (owned by vlustun, 1000). Writes from uid 33 into the bind-mounted `vendor/` are denied.
- [x] Immediate workaround, no rebuild: run `docker compose exec -u root app sh -c 'mkdir -p /tmp/composer && chown -R 1000:1000 vendor var'`. Then run `docker compose exec -u 1000:1000 -e COMPOSER_HOME=/tmp/composer app composer install`. Or run `make install RUN="docker compose run --rm php"`.
- [x] Permanent fix in compose.xdebug.yaml: add `user: "${UID:-1000}:${GID:-1000}"` and set `COMPOSER_HOME: /tmp/composer` and `HOME: /tmp` for the `app` service. Then the bind-mounted tree is written with the host's ownership.
- [x] In the Dockerfile `fpm-dev` stage, set `ENV COMPOSER_HOME=/tmp/composer`. Make sure `var/` is writable by the runtime UID: either `chmod -R a+rwX var`, or rely on the host-owned mount. This avoids the "Cannot create cache directory /var/www/.composer" warning.
- [x] Update the Makefile so `install` runs through the `php` tools service by default, or add `install-app`, so `make install` works without the user having to pass `RUN=`. Export UID and GID in the Makefile with `export UID ?= $(shell id -u)` and `export GID ?= $(shell id -g)`.
- [x] Rebuild and restart with `docker compose -f docker-compose.yml -f compose.xdebug.yaml up -d --build app`. Then run `make install` and check that `vendor/composer/pcre` exists, the PHP-FPM healthcheck passes and Xdebug still connects.
- [x] Make sure `vendor/` is not left root-owned from earlier runs. Run `sudo chown -R $USER:$USER vendor var` on the host if needed.
- [x] Add a short troubleshooting note to the README covering the uid mismatch and the commands above.
