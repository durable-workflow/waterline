FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM node:24.22.1-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS node

FROM ubuntu:22.04

LABEL maintainer="Taylor Otwell"

ARG WWWGROUP
ARG POSTGRES_VERSION=15
# Reviewed signing keys (waterline#291). A changed key fails the build until reviewed.
ARG PGDG_KEY_SHA256=0144068502a1eddd2a0280ede10ef607d1ec592ce819940991203941564e8e76
ARG MICROSOFT_KEY_SHA256=2fa9c05d591a1582a9aba276272478c262e95ad00acf60eaee1644d93941e3c6

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND noninteractive
ENV TZ=UTC

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

COPY --from=node /usr/local/ /usr/local/
COPY --from=node /opt/yarn-v1.22.22/ /opt/yarn-v1.22.22/
COPY verify-node-runtime.mjs /usr/local/share/waterline/verify-node-runtime.mjs

# No APT download retries before the first networked apt-get: CVE-2026-109518
# (durable-workflow/.github#295).
RUN echo 'Acquire::Retries "0";' > /etc/apt/apt.conf.d/99-no-retries

# Every curl in this build is HTTPS-only, including redirects (waterline#291).
RUN printf 'proto = "=https"\nproto-redir = "=https"\nfail\n' > /root/.curlrc \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        dnsutils \
        git \
        gnupg \
        gosu \
        libcap2-bin \
        libpng-dev \
        python2 \
        sqlite3 \
        sudo \
        supervisor \
        unzip \
        zip \
    && apt-get install -y --no-install-recommends \
        chromium-browser \
        libasound2 \
        libatk-bridge2.0-0 \
        libatk1.0-0 \
        libatspi2.0-0 \
        libcairo2 \
        libgbm1 \
        libnspr4 \
        libnss3 \
        libpango-1.0-0 \
        libxcomposite1 \
        libxdamage1 \
        libxfixes3 \
        libxkbcommon0 \
        libxrandr2 \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' \
        | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main" \
        > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.4-bcmath \
        php8.4-cli \
        php8.4-curl \
        php8.4-dev \
        php8.4-gd \
        php8.4-igbinary \
        php8.4-imagick \
        php8.4-imap \
        php8.4-intl \
        php8.4-ldap \
        php8.4-mbstring \
        php8.4-memcached \
        php8.4-msgpack \
        php8.4-mysql \
        php8.4-pcov \
        php8.4-pgsql \
        php8.4-readline \
        php8.4-redis \
        php8.4-soap \
        php8.4-sqlite3 \
        php8.4-swoole \
        php8.4-xdebug \
        php8.4-xml \
        php8.4-zip \
    && node /usr/local/share/waterline/verify-node-runtime.mjs \
    && curl -sS https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /tmp/pgdg.asc \
    && echo "${PGDG_KEY_SHA256}  /tmp/pgdg.asc" | sha256sum -c - \
    && gpg --dearmor < /tmp/pgdg.asc > /etc/apt/keyrings/pgdg.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] https://apt.postgresql.org/pub/repos/apt jammy-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends mysql-client \
    && apt-get install -y --no-install-recommends "postgresql-client-${POSTGRES_VERSION}" \
    && apt-get install -y --no-install-recommends \
        odbcinst \
        unixodbc \
        unixodbc-dev \
    && curl -sS https://packages.microsoft.com/keys/microsoft.asc -o /tmp/microsoft.asc \
    && echo "${MICROSOFT_KEY_SHA256}  /tmp/microsoft.asc" | sha256sum -c - \
    && gpg --dearmor < /tmp/microsoft.asc > /etc/apt/keyrings/microsoft.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/microsoft.gpg] https://packages.microsoft.com/ubuntu/22.04/prod jammy main" \
        > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends \
        msodbcsql17 \
        mssql-tools \
    && echo 'export PATH="$PATH:/opt/mssql-tools/bin"' >> ~/.bashrc \
    && echo 'export PATH="$PATH:/opt/mssql-tools/bin"' >> /etc/profile.d/mssql.sh \
    && apt-get install -y --no-install-recommends \
        g++ \
        make \
        php-pear \
        php8.4-dev \
        php8.4-xml \
    && pecl channel-update pecl.php.net \
    && pecl install sqlsrv \
    && pecl install pdo_sqlsrv \
    && echo "extension=sqlsrv.so" > /etc/php/8.4/mods-available/sqlsrv.ini \
    && echo "extension=pdo_sqlsrv.so" > /etc/php/8.4/mods-available/pdo_sqlsrv.ini \
    && phpenmod sqlsrv pdo_sqlsrv \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /root/.curlrc /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN setcap "cap_net_bind_service=+ep" /usr/bin/php8.4 \
    && groupadd --force -g 1000 laravel \
    && useradd -ms /bin/bash --no-user-group -g 1000 -u 1337 laravel \
    && echo "laravel ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

COPY start-container /usr/local/bin/start-container
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php.ini /etc/php/8.4/cli/conf.d/99-laravel.ini
RUN sed -i 's/\r$//' /usr/local/bin/start-container \
    && chmod +x /usr/local/bin/start-container

EXPOSE 8000

ENTRYPOINT ["start-container"]
