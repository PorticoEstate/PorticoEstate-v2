FROM php:8-apache

LABEL maintainer="Sigurd Nes <sigurdne@gmail.com>"

# Define build arguments
ARG INSTALL_MSSQL=false
ARG INSTALL_XDEBUG=false
ARG INSTALL_ORACLE=false

# Define build argument for OCI8 version
ARG OCI8_VERSION=3.4.0

# Install necessary packages
RUN apt-get update && apt-get install -y software-properties-common \
    apt-utils libcurl4-openssl-dev libicu-dev libxslt-dev libpq-dev zlib1g-dev libpng-dev libc-client-dev libkrb5-dev libzip-dev libonig-dev \
    git \
    less vim-tiny \
    apg \
    sudo \
    libaio1 locales wget \
	libmagickwand-dev --no-install-recommends


# Configure PEAR
RUN if [ -n "${http_proxy}" ]; then pear config-set http_proxy ${http_proxy}; fi && \
    pear config-set php_ini $PHP_INI_DIR/php.ini

# Install PHP extensions
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install curl intl xsl pdo_pgsql pdo_mysql gd \
	imap shmop soap zip mbstring ftp calendar exif

	# Install PECL extensions
RUN pecl install xdebug apcu && docker-php-ext-enable xdebug apcu
RUN pecl install redis && docker-php-ext-enable redis
# RUN pecl install imagick && docker-php-ext-enable imagick


# Temp solution to imagick broken with php >= 8.3, use imagick commit 28f27044e435a2b203e32675e942eb8de620ee58

RUN ( curl -sSLf https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o - || echo 'return 1' ) | sh -s

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

# Configure locales
RUN locale-gen --purge en_US.UTF-8
ENV LC_ALL=en_US.UTF-8
ENV LANG=en_US.UTF-8
ENV LANGUAGE=en_US.UTF-8

# Apache2 configuration
ENV APACHE_RUN_USER=www-data
ENV APACHE_RUN_GROUP=www-data
ENV APACHE_LOG_DIR=/var/log/apache2
ENV APP_DOCUMENT_ROOT=/var/www/html

EXPOSE 80

RUN apt-get update && apt-get install -y ssl-cert

RUN a2enmod rewrite
RUN a2enmod headers
RUN a2enmod ssl
RUN a2enmod proxy
RUN a2enmod proxy_http

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

# Download and install OpenJDK
#RUN wget -O /tmp/openjdk.deb https://download.oracle.com/java/21/latest/jdk-21_linux-x64_bin.deb \
#    && dpkg -i /tmp/openjdk.deb \
#    && rm /tmp/openjdk.deb

# insert microsoft repo if ${INSTALL_MSSQL} is not true or not set
RUN if [ "${INSTALL_MSSQL}" != "true" ]; then \
    wget -qO - https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /etc/apt/trusted.gpg.d/microsoft.asc.gpg && \
    echo "deb [arch=amd64] https://packages.microsoft.com/debian/$(cat /etc/debian_version | cut -d. -f1)/prod $(lsb_release -cs) main" > /etc/apt/sources.list.d/mssql-release.list; \
   fi

RUN apt-get update && apt-get install -y msopenjdk-21 unzip

# Set JAVA_HOME environment variable
#ENV JAVA_HOME=/usr/lib/jvm/jdk-21
#ENV PATH=$JAVA_HOME/bin:$PATH
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

# Define build argument for PHP version
# Get PHP version and store it in an environment variable
RUN PHP_VERSION=$(php -r "echo phpversion();") && echo "PHP_VERSION=${PHP_VERSION}" > /env.sh

# Source the environment variable and use it in subsequent instructions
RUN . /env.sh && echo "The PHP version is ${PHP_VERSION}"

# Conditionally install Oracle suppor
RUN if [ "${INSTALL_ORACLE}" = "true" ]; then \
    export OCI8_VERSION=${OCI8_VERSION}; \
    export PHP_VERSION=${PHP_VERSION}; \
    # Unzip Oracle Instant Client
    unzip -o /tmp/instantclient-sdk-linux.x64-12.2.0.1.0.zip -d /usr/local/lib/; \
    unzip -o /tmp/instantclient-basic-linux.x64-12.2.0.1.0.zip -d /usr/local/lib/; \
    # Create symbolic links
    ln -s /usr/local/lib/instantclient_12_2/libclntsh.so.12.1 /usr/local/lib/instantclient_12_2/libclntsh.so; \
    mkdir -p /usr/local/lib/instantclient_12_2/lib/oracle/12.2; \
    ln -s /usr/local/lib/instantclient_12_2/sdk/ /usr/local/lib/instantclient_12_2/lib/oracle/12.2/client; \
    ln -s /usr/local/lib/instantclient_12_2 /usr/local/lib/instantclient_12_2/lib/oracle/12.2/client/lib; \
    # Enable OCI8 extension
	cd /tmp/; \
	pecl download oci8; \
    tar xzvf oci8-${OCI8_VERSION}.tgz; \
    cd oci8-${OCI8_VERSION}; \
    phpize; \
    ./configure --with-oci8=shared,instantclient,/usr/local/lib/instantclient_12_2; \
    make; \
    make install; \
    # Enable PDO_OCI extension
    cd /tmp/; \
    wget https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz; \
    tar -xzf php-${PHP_VERSION}.tar.gz; \
    cd php-${PHP_VERSION}/ext/pdo_oci; \
    phpize; \
    ./configure --with-pdo-oci=instantclient,/usr/local/lib/instantclient_12_2,12.2; \
    make; \
    make install; \
    # Enable extensions in php.ini
    echo 'extension=oci8.so' >> /usr/local/etc/php/php.ini; \
    echo 'extension=pdo_oci.so' >> /usr/local/etc/php/php.ini; \
    # Clean up
    rm -rf /tmp/oci8-${OCI8_VERSION}.tgz /tmp/oci8-${OCI8_VERSION} /tmp/php-${PHP_VERSION}.tar.gz /tmp/php-${PHP_VERSION} /tmp/instantclient-sdk-linux.x64-12.2.0.1.0.zip /tmp/instantclient-basic-linux.x64-12.2.0.1.0.zip; \
fi


# Clean up
RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN mkdir -p /var/public/files
RUN chmod 777 /var/public/files

# Copy and enable Apache configuration for Next.js
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

CMD ["apache2-foreground"]