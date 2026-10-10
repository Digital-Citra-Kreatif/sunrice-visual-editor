#!/usr/bin/env bash
# Builds the workbench app (publish assets and templates, migrate, seed),
# serves it and checks the seeded homepage and the admin sign-in page.
set -euo pipefail

vendor/bin/testbench workbench:build --no-ansi

vendor/bin/testbench serve --port=8089 --no-ansi > .ci/serve.log 2>&1 &
server=$!
trap 'kill $server 2>/dev/null || true' EXIT

for attempt in $(seq 1 30); do
    curl -fsS -o /dev/null http://127.0.0.1:8089/cms/login && break
    sleep 1
done

home=$(curl -sS -w '\n%{http_code}' http://127.0.0.1:8089/)
echo "GET / -> $(echo "$home" | tail -n 1)"
if ! echo "$home" | grep -q 'Sunrice Demo' || ! echo "$home" | grep -q 'Templates you can see'; then
    echo "The homepage did not show the seeded site:"
    echo "$home" | head -n 60
    echo "--- server log"
    tail -n 40 .ci/serve.log
    exit 1
fi

login=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8089/cms/login)
echo "GET /cms/login -> $login"
[ "$login" = "200" ]
