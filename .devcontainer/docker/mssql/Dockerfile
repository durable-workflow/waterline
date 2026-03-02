FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV ACCEPT_EULA=Y

RUN apt-get update \
    && apt-get install -y ca-certificates curl gnupg apt-transport-https \
    && printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && chmod +x /usr/sbin/policy-rc.d \
    && curl -sSL -O https://packages.microsoft.com/config/ubuntu/22.04/packages-microsoft-prod.deb \
    && dpkg -i packages-microsoft-prod.deb \
    && rm packages-microsoft-prod.deb \
    && curl -fsSL https://packages.microsoft.com/config/ubuntu/22.04/mssql-server-2022.list > /etc/apt/sources.list.d/mssql-server-2022.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y mssql-server msodbcsql18 mssql-tools18 unixodbc-dev libgssapi-krb5-2 \
    && rm -f /usr/sbin/policy-rc.d \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY start-mssql.sh /usr/local/bin/start-mssql.sh

RUN chmod +x /usr/local/bin/start-mssql.sh \
    && mkdir -p /var/opt/mssql

EXPOSE 1433

ENTRYPOINT ["/bin/bash", "/usr/local/bin/start-mssql.sh"]
