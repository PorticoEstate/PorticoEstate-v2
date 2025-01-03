# Use an official PHP-FPM base image
FROM php:8.4-fpm

LABEL maintainer="Sigurd Nes <sigurdne@gmail.com>"

# Define build arguments
ARG INSTALL_MSSQL=false
ARG INSTALL_XDEBUG=false
ARG INSTALL_ORACLE=false


# Install necessary packages
RUN apt-get update && apt-get install -y software-properties-common \
    apt-utils libcurl4-openssl-dev libicu-dev libxslt-dev libpq-dev zlib1g-dev libpng-dev libc-client-dev libkrb5-dev libzip-dev libonig-dev \
    git \
    less vim-tiny \
    apg \
    sudo \
    libaio1 locales wget \
    libmagickwand-dev --no-install-recommends \
    apache2 libapache2-mod-fcgid ssl-cert \
	cron \
	iputils-ping \
	wkhtmltopdf

RUN touch /etc/cron.d/cronjob && chmod 0644 /etc/cron.d/cronjob

# Generate the specified locale
RUN locale-gen --purge en_US.UTF-8

# Set environment variables
ENV LC_ALL=en_US.UTF-8
ENV LANG=en_US.UTF-8
ENV LANGUAGE=en_US.UTF-8


# Download and install the install-php-extensions script
RUN curl -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o /usr/local/bin/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions

# Configure PEAR
RUN if [ -n "${http_proxy}" ]; then pear config-set http_proxy ${http_proxy}; fi && \
    pear config-set php_ini $PHP_INI_DIR/php.ini

# Install PHP extensions
RUN docker-php-ext-install curl intl xsl pdo_pgsql pdo_mysql gd \
    shmop soap zip mbstring ftp calendar exif

RUN install-php-extensions imap

# Install PECL extensions
RUN pecl install xdebug apcu && docker-php-ext-enable xdebug apcu
RUN pecl install redis && docker-php-ext-enable redis

# Install Imagick
# Temp solution to imagick broken with php >= 8.3, use imagick commit 28f27044e435a2b203e32675e942eb8de620ee58
RUN install-php-extensions imagick

# Install Composer
RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
RUN php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Conditionally install MSSQL support
RUN if [ "${INSTALL_MSSQL}" = "true" ]; then \
    wget -qO - https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /etc/apt/trusted.gpg.d/microsoft.asc.gpg && \
    echo "deb [arch=amd64] https://packages.microsoft.com/debian/$(cat /etc/debian_version | cut -d. -f1)/prod $(lsb_release -cs) main" > /etc/apt/sources.list.d/mssql-release.list && \
    apt-get update && \
    ACCEPT_EULA=Y apt-get install -y msodbcsql17 && \
    ACCEPT_EULA=Y apt-get install -y mssql-tools18 && \
    apt-get install -y unixodbc unixodbc-dev && \
    pecl install sqlsrv pdo_sqlsrv && \
    docker-php-ext-enable sqlsrv pdo_sqlsrv; \
    fi

# PHP configuration
RUN if [ "${INSTALL_XDEBUG}" = "true" ]; then \
    echo 'xdebug.mode=debug,develop' >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo 'xdebug.discover_client_host=1' >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo 'xdebug.client_host=host.docker.internal' >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo 'xdebug.start_with_request=yes' >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo 'xdebug.idekey=netbeans-xdebug' >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
   fi

RUN echo 'session.cookie_secure=Off' >> /usr/local/etc/php/php.ini
RUN echo 'session.use_cookies=On' >> /usr/local/etc/php/php.ini
RUN echo 'session.use_only_cookies=On' >> /usr/local/etc/php/php.ini
RUN echo 'short_open_tag=Off' >> /usr/local/etc/php/php.ini
RUN echo 'request_order = "GPCS"' >> /usr/local/etc/php/php.ini
RUN echo 'variables_order = "GPCS"' >> /usr/local/etc/php/php.ini
RUN echo 'memory_limit = 5048M' >> /usr/local/etc/php/php.ini
RUN echo 'max_input_vars = 5000' >> /usr/local/etc/php/php.ini
RUN echo 'error_reporting = E_ALL & ~E_NOTICE' >> /usr/local/etc/php/php.ini
RUN echo 'post_max_size = 20M' >> /usr/local/etc/php/php.ini
RUN echo 'upload_max_filesize = 8M' >> /usr/local/etc/php/php.ini

# insert microsoft repo if ${INSTALL_MSSQL} is not true or not set
RUN if [ "${INSTALL_MSSQL}" != "true" ]; then \
    wget -qO - https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /etc/apt/trusted.gpg.d/microsoft.asc.gpg && \
    echo "deb [arch=amd64] https://packages.microsoft.com/debian/$(cat /etc/debian_version | cut -d. -f1)/prod $(lsb_release -cs) main" > /etc/apt/sources.list.d/mssql-release.list; \
   fi

RUN apt-get update && apt-get install -y msopenjdk-21 unzip

## Verify Java installation
RUN java -version

# Copy all content from the host oracle directory to /tmp in the build context
# https://www.oracle.com/database/technologies/instant-client/linux-x86-64-downloads.html
COPY oracle/ /tmp/

# Set environment variables for Oracle support
ENV LD_LIBRARY_PATH=/usr/local/lib/instantclient_12_2
ENV TNS_ADMIN=/usr/local/lib/instantclient_12_2
ENV ORACLE_BASE=/usr/local/lib/instantclient_12_2
ENV ORACLE_HOME=/usr/local/lib/instantclient_12_2

# Conditionally install Oracle support
RUN if [ "${INSTALL_ORACLE}" = "true" ]; then \
    echo "Installing Oracle support..."; \
    # Unzip Oracle Instant Client
    unzip -o /tmp/instantclient-sdk-linux.x64-12.2.0.1.0.zip -d /usr/local/lib/; \
    unzip -o /tmp/instantclient-basic-linux.x64-12.2.0.1.0.zip -d /usr/local/lib/; \
    # Create symbolic links
    ln -s /usr/local/lib/instantclient_12_2/libclntsh.so.12.1 /usr/local/lib/instantclient_12_2/libclntsh.so; \
    mkdir -p /usr/local/lib/instantclient_12_2/lib/oracle/12.2; \
    ln -s /usr/local/lib/instantclient_12_2/sdk/ /usr/local/lib/instantclient_12_2/lib/oracle/12.2/client; \
    ln -s /usr/local/lib/instantclient_12_2 /usr/local/lib/instantclient_12_2/lib/oracle/12.2/client/lib; \
    # Install OCI8 and PDO_OCI extensions
    install-php-extensions oci8 pdo_oci; \
    # Clean up
    rm -rf /tmp/instantclient-sdk-linux.x64-12.2.0.1.0.zip /tmp/instantclient-basic-linux.x64-12.2.0.1.0.zip; \
else \
    echo "Skipping Oracle support installation."; \
fi


RUN mkdir -p /var/public/files
RUN chmod 777 /var/public/files

# Ensure PHP-FPM socket directory exists
RUN mkdir -p /run/php && chown www-data:www-data /run/php

# Update PHP-FPM configuration to use Unix socket
RUN sed -i 's|^listen = .*|listen = /run/php/php8.3-fpm.sock|' /usr/local/etc/php-fpm.d/www.conf \
    && echo 'listen.owner = www-data' >> /usr/local/etc/php-fpm.d/www.conf \
    && echo 'listen.group = www-data' >> /usr/local/etc/php-fpm.d/www.conf \
    && echo 'listen.mode = 0660' >> /usr/local/etc/php-fpm.d/www.conf

# Alternative: Update PHP-FPM configuration to use TCP socket
#RUN sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf

# Update include directive in php-fpm.conf
RUN sed -i 's|^include=.*|include=/usr/local/etc/php-fpm.d/*.conf|' /usr/local/etc/php-fpm.conf

# Comment out conflicting listen directives
RUN sed -i 's|^listen = .*|;listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf.default
RUN sed -i 's|^listen = .*|;listen = 9000|' /usr/local/etc/php-fpm.d/zz-docker.conf

# Copy PHP-FPM configuration
COPY php-fpm.conf /etc/apache2/conf-available/php-fpm.conf

# Apache2 configuration
ENV APACHE_RUN_USER=www-data
ENV APACHE_RUN_GROUP=www-data
ENV APACHE_LOG_DIR=/var/log/apache2
ENV APP_DOCUMENT_ROOT=/var/www/html

EXPOSE 80


# Enable Apache modules
RUN a2enmod proxy_fcgi setenvif
RUN a2enconf php-fpm
RUN a2enmod rewrite
RUN a2enmod headers
RUN a2enmod ssl
RUN a2enmod proxy
RUN a2enmod proxy_http

# Copy Apache configuration
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Clean up
RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/

# Make entrypoint script executable
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set entrypoint
ENTRYPOINT ["docker-entrypoint.sh"]