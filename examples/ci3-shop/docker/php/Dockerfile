FROM php:7.3-apache-buster

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG CODEIGNITER_VERSION=3.1.13
ARG ORACLE_IC_VERSION=23.26.2.0.0
ARG TARGETARCH

RUN set -eux; \
    sed -i 's#http://deb.debian.org/debian#http://archive.debian.org/debian#g' /etc/apt/sources.list; \
    sed -i 's#http://security.debian.org/debian-security#http://archive.debian.org/debian-security#g' /etc/apt/sources.list; \
    printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99archive; \
    apt-get update \
    && apt-get install -y --no-install-recommends unzip curl libaio1 git ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    if [ "${TARGETARCH:-amd64}" = "arm64" ]; then ic_arch="arm64"; else ic_arch="x64"; fi; \
    ic_dir="$(echo "$ORACLE_IC_VERSION" | tr -d '.')"; \
    curl -fsSL -o /tmp/instantclient-basiclite.zip "https://download.oracle.com/otn_software/linux/instantclient/${ic_dir}/instantclient-basiclite-linux.${ic_arch}-${ORACLE_IC_VERSION}.zip"; \
    curl -fsSL -o /tmp/instantclient-sdk.zip "https://download.oracle.com/otn_software/linux/instantclient/${ic_dir}/instantclient-sdk-linux.${ic_arch}-${ORACLE_IC_VERSION}.zip"; \
    unzip -oq /tmp/instantclient-basiclite.zip -d /opt/oracle; \
    unzip -oq /tmp/instantclient-sdk.zip -d /opt/oracle; \
    ln -s /opt/oracle/instantclient_* /opt/oracle/instantclient; \
    echo /opt/oracle/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf; \
    ldconfig; \
    printf "instantclient,/opt/oracle/instantclient\n" | pecl install oci8-2.2.0; \
    docker-php-ext-enable oci8; \
    rm -f /tmp/instantclient-basiclite.zip /tmp/instantclient-sdk.zip

RUN curl -fsSL -o /tmp/ci.zip "https://github.com/bcit-ci/CodeIgniter/archive/refs/tags/${CODEIGNITER_VERSION}.zip" \
    && unzip -q /tmp/ci.zip -d /tmp \
    && rm -rf /var/www/html/* \
    && cp -R "/tmp/CodeIgniter-${CODEIGNITER_VERSION}/"* /var/www/html/ \
    && rm -rf /tmp/ci.zip "/tmp/CodeIgniter-${CODEIGNITER_VERSION}"

COPY composer.json composer.lock /opt/tekagami/
COPY src /opt/tekagami/src
COPY bin /opt/tekagami/bin
RUN cd /opt/tekagami && composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY examples/ci3-shop/app/application /var/www/html/application
COPY examples/ci3-shop/app/public/index.php /var/www/html/index.php
COPY examples/ci3-shop/app/public/.htaccess /var/www/html/.htaccess

RUN mkdir -p /var/www/html/var \
    && chown -R www-data:www-data /var/www/html/application /var/www/html/var \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\nServerName localhost\n' > /etc/apache2/conf-available/ci3-shop.conf \
    && a2enmod rewrite \
    && a2enconf ci3-shop
