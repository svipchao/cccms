# syntax=docker/dockerfile:1
#
# 生产镜像（多阶段构建）：
#
#   builder 阶段 —— 装编译工具链、编译 PHP 扩展、`composer install`，完成后整层丢弃；
#   runtime 阶段 —— 只从 builder 拷回「PHP 配置 / 扩展 / vendor / 源码」，
#                   不带 gcc、make、linux-headers 这些**只在编译期需要**的东西。
#
# 与旧版单阶段构建相比：镜像更小（编译工具链不进最终层），
# 且 `composer install` 与 `COPY . .` 仍是两层，源码变更不会触发重新装依赖（构建缓存可复用）。
#
# 用法见 docs/02-安装部署.md。

# ---------------------------------------------------------------------
# 构建层：编译扩展 + 安装依赖
# ---------------------------------------------------------------------
FROM php:8.3-cli-alpine AS builder

RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories

# 1 = 安装 pecl 的 redis 扩展（性能更好）；0 = 仅使用纯 PHP 的 predis（composer 已依赖）
ARG INSTALL_REDIS_EXT=1
RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_mysql pcntl; \
    docker-php-ext-enable opcache; \
    if [ "$INSTALL_REDIS_EXT" = "1" ]; then \
        pecl install redis; \
        docker-php-ext-enable redis; \
    fi; \
    apk del .build-deps; \
    rm -rf /var/cache/apk/* /tmp/pear

# 生产用 php.ini（关闭开发期错误回显），随 PHP 配置目录一起拷到运行层
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 依赖层：只有 composer.json / composer.lock 变化才重装
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

# ---------------------------------------------------------------------
# 运行层：只带产物
# ---------------------------------------------------------------------
FROM php:8.3-cli-alpine AS runtime

WORKDIR /app

# PHP 运行配置（php.ini + conf.d 里的扩展启用项）与编译好的扩展
COPY --from=builder /usr/local/etc/php /usr/local/etc/php
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# 依赖与源码
# 注意顺序：vendor 先拷，再由 `COPY . .` 覆盖源码；`.dockerignore` 已排除 vendor，
# 因此 `COPY . .` 不会把空的 host vendor 覆盖上来。
COPY --from=builder /app/vendor /app/vendor
COPY . .

# 依赖补丁必须在源码就位后执行（patch 会改 vendor 里的第三方文件）。
# 构建用的是 `--no-scripts`，跳过了 composer 的 post-autoload-dump，
# 这里显式补一次：否则生产容器在 PHP 8.3 下会持续输出 Qiniu SDK 的弃用告警，
# 而本地 / CI（都走了 post-autoload-dump）看不到。
RUN php support/vendor_patch.php

# 非 root 运行：应用只需要写 runtime/ 与 public/storage/，其余目录只读。
# 命名卷首次挂载时会继承镜像里这两个目录的属主，因此这里的 chown 对持久化也有效。
RUN addgroup -S cccms \
    && adduser -S -G cccms cccms \
    && mkdir -p runtime public/storage \
    && chown -R cccms:cccms runtime public/storage

USER cccms

# 生产默认关闭 debug（异常详情不外泄）；本地调试可在运行时覆盖为 true
ENV APP_DEBUG=false

EXPOSE 8787

# 就绪探针探 /healthz：逐项检查 MySQL / Redis / 磁盘 / 内存，任一故障返回 503
# （wget 对 5xx 会以非 0 退出，因此「依赖不可用」能正确反映为 unhealthy）。
# `/ping` 仍保留为不碰外部依赖的存活探针，两者分工见 docs/11-常用命令与运维。
HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
    CMD wget -q -O- http://127.0.0.1:8787/healthz > /dev/null 2>&1 || exit 1

CMD ["php", "start.php", "start"]
