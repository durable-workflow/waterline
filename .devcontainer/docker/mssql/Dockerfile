FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV ACCEPT_EULA=Y

# Reviewed Microsoft repository package (waterline#291). A changed package fails the build until reviewed.
ARG MICROSOFT_PROD_DEB_SHA256=0d335c06ceb3227e330a36a7135997207843c726e42ec341e34a3825f151213b

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg apt-transport-https \
    && printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && chmod +x /usr/sbin/policy-rc.d \
    && curl --proto '=https' --proto-redir '=https' -fsSL https://packages.microsoft.com/config/ubuntu/22.04/packages-microsoft-prod.deb -o /tmp/packages-microsoft-prod.deb \
    && echo "${MICROSOFT_PROD_DEB_SHA256}  /tmp/packages-microsoft-prod.deb" | sha256sum -c - \
    && dpkg -i /tmp/packages-microsoft-prod.deb \
    && rm /tmp/packages-microsoft-prod.deb \
    && echo 'deb [arch=amd64,arm64,armhf] https://packages.microsoft.com/ubuntu/22.04/mssql-server-2022 jammy main' > /etc/apt/sources.list.d/mssql-server-2022.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends mssql-server msodbcsql18 mssql-tools18 unixodbc-dev libgssapi-krb5-2 \
    && rm -f /usr/sbin/policy-rc.d \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY start-mssql.sh /usr/local/bin/start-mssql.sh

RUN chmod +x /usr/local/bin/start-mssql.sh \
    && mkdir -p /var/opt/mssql

EXPOSE 1433

ENTRYPOINT ["/bin/bash", "/usr/local/bin/start-mssql.sh"]
