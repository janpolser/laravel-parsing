FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
git \
curl \
unzip \
zip \
libzip-dev \
libxml2-dev \
libcurl4-openssl-dev \
libssl-dev \
libonig-dev \
gnupg \
ca-certificates \
wget \
dumb-init \
&& apt-get clean \
&& rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install \
zip \
mbstring \
xml \
pcntl \
bcmath \
sockets \
curl

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
&& apt-get install -y nodejs \
&& npm install -g npm

RUN npm install -g playwright @playwright/test \
&& npx playwright install chromium

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN curl -fsSL -o /usr/local/bin/supercronic \
https://github.com/aptible/supercronic/releases/download/v0.2.29/supercronic-linux-amd64 \
&& chmod +x /usr/local/bin/supercronic

RUN echo "0 * * * * cd /var/www && php artisan schedule:run >> /proc/1/fd/1 2>/proc/1/fd/2" > /etc/crontab \
&& chmod 0644 /etc/crontab

WORKDIR /var/www

ENTRYPOINT ["dumb-init", "--"]
CMD ["supercronic", "/etc/crontab"]
