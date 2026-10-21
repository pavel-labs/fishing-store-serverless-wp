FROM public.ecr.aws/amazonlinux/amazonlinux:2023 AS build

RUN dnf install -y gcc gcc-c++ make autoconf bzip2 tar gzip findutils \
    libxml2-devel libzip-devel libcurl-devel openssl-devel bzip2-devel \
    libjpeg-turbo-devel libpng-devel freetype-devel oniguruma-devel \
    libwebp-devel sqlite-devel zlib-devel diffutils \
    && dnf clean all

WORKDIR /build
ARG PHP_VERSION=8.3.36
ARG PHP_SHA256=46f4b45e3688ed2b1819015a7b532c15b51cc055bdd3b13fd00ddfeebeedcd5f
RUN curl --fail --location --retry 3 "https://www.php.net/distributions/php-${PHP_VERSION}.tar.bz2" -o php.tar.bz2 \
    && echo "${PHP_SHA256}  php.tar.bz2" | sha256sum --check --strict \
    && tar -xjf php.tar.bz2
WORKDIR /build/php-${PHP_VERSION}
RUN ./configure --prefix=/opt/php --without-pear --disable-cgi --disable-phpdbg \
    --with-openssl --with-curl --with-libxml --enable-simplexml --enable-xml \
    --enable-gd --with-jpeg --with-webp --with-freetype --enable-exif \
    --enable-mbstring --enable-sockets --enable-pdo --with-pdo-mysql=mysqlnd \
    --enable-tokenizer --with-zip --with-mysqli=mysqlnd --with-bz2 \
    --with-zlib --with-gettext --enable-opcache \
    && make -j2 \
    && make install

RUN mkdir -p /runtime/lib /runtime/exts /runtime/licenses \
    && cp /opt/php/bin/php /runtime/php \
    && cp /opt/php/lib/php/extensions/no-debug-non-zts-20230831/opcache.so /runtime/exts/ \
    && cp LICENSE /runtime/licenses/PHP-LICENSE \
    && cp -L /usr/lib64/libsqlite3.so.0 /runtime/lib/ \
    && ldd /runtime/php /runtime/exts/opcache.so /runtime/lib/libsqlite3.so.0 \
       | awk '/=> \/.+/ { print $3 }' | sort -u \
       | while read -r library; do \
           case "$(basename "$library")" in \
             libc.so.*|libm.so.*|libdl.so.*|libpthread.so.*|librt.so.*|libresolv.so.*|ld-linux*) ;; \
             *) cp -L "$library" /runtime/lib/ ;; \
           esac; \
         done \
    && cp -a /usr/share/licenses/. /runtime/licenses/ \
    && strip /runtime/php /runtime/exts/*.so /runtime/lib/*.so*
COPY build/php/php.ini /runtime/php.ini
RUN cd /runtime \
    && LD_LIBRARY_PATH=/runtime/lib LD_PRELOAD=/runtime/lib/libsqlite3.so.0 ./php -c php.ini -r \
       'if (PHP_VERSION !== "8.3.36" || ini_get("session.upload_progress.enabled")) { exit(1); } foreach (["mysqli", "pdo_mysql", "pdo_sqlite", "curl", "openssl", "mbstring", "gd", "zip", "exif", "Zend OPcache"] as $name) { if (!extension_loaded($name)) { fwrite(STDERR, "Missing extension: $name\n"); exit(1); } } echo PHP_VERSION, " runtime built successfully\n";'

FROM scratch AS artifact
COPY --from=build /runtime/ /
