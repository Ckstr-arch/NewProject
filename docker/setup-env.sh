#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
command -v openssl >/dev/null 2>&1 || {
    echo 'OpenSSL is required to generate local passwords and the JWT secret.' >&2
    exit 1
}

umask 077
if [ ! -e .env.docker ]; then
    : > .env.docker
fi

# Preserve existing nonempty values, including credentials from an earlier setup.
# A missing/blank key gets a new final entry; Compose uses the last occurrence.
for key in DB_PASSWORD DB_ROOT_PASSWORD JWT_SECRET; do
    if ! awk -F= -v wanted="$key" '
        $1 == wanted {
            value = substr($0, index($0, "=") + 1)
            gsub(/^[ \t]+|[ \t]+$/, "", value)
            configured = (value != "" && value != "\"\"" && value != "\047\047")
        }
        END { exit !configured }
    ' .env.docker; then
        printf '\n%s=%s\n' "$key" "$(openssl rand -hex 32)" >> .env.docker
    fi
done

chmod 600 .env.docker
echo 'Private .env.docker is ready. Existing nonempty values were preserved.'
