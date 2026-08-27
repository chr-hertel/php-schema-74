# shellcheck shell=bash
#
# Dispatches a command to PHP 7.4: the interpreter on PATH when it already is
# 7.4 — which is how CI runs — and a container otherwise.

PHP74_IMAGE="${PHP74_IMAGE:-php:7.4-cli}"

php74_is_native() {
    command -v php > /dev/null 2>&1 \
        && php -r 'exit(\PHP_MAJOR_VERSION === 7 && \PHP_MINOR_VERSION === 4 ? 0 : 1);' > /dev/null 2>&1
}

# Runs a shell snippet with the project mounted at its own root.
php74_sh() {
    if php74_is_native; then
        (cd "$ROOT" && sh -c "$1")
    else
        docker run --rm -v "$ROOT:/app" -w /app "$PHP74_IMAGE" sh -c "$1"
    fi
}
