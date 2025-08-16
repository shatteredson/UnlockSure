FROM wordpress:6.5-apache

# Basics + WP-CLI
RUN apt-get update && apt-get install -y less mariadb-client unzip curl nano git \
 && curl -o /usr/local/bin/wp -L https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp

# Node LTS (optional for asset builds)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y nodejs

# PHP limits
RUN printf "upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=512M\nmax_execution_time=300\n" > /usr/local/etc/php/conf.d/uploads.ini
