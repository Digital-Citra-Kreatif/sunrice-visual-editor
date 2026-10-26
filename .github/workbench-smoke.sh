#!/usr/bin/env bash
# Builds the workbench app (`composer workbench`: publish assets and templates, migrate, seed),
# serves it and checks the seeded homepage and the admin sign-in page.
# Arguments, if any, are a command to run while the site is up:
#   .github/workbench-smoke.sh npm run test:browser
set -euo pipefail

# The database file has to exist before the build starts: Testbench falls
# back to an in-memory database for a whole command when it is missing.
composer workbench --no-ansi

vendor/bin/testbench serve --port=8000 --no-ansi > .ci/serve.log 2>&1 &
server=$!
trap 'kill $server 2>/dev/null || true' EXIT

for attempt in $(seq 1 30); do
    curl -fsS -o /dev/null http://127.0.0.1:8000/admin/login && break
    sleep 1
done

home=$(curl -sS -w '\n%{http_code}' http://127.0.0.1:8000/)
echo "GET / -> $(echo "$home" | tail -n 1)"
if ! echo "$home" | grep -q 'Sunrice Demo' || ! echo "$home" | grep -q 'Templates you can see'; then
    echo "The homepage did not show the seeded site:"
    echo "$home" | head -n 60
    echo "--- server log"
    tail -n 40 .ci/serve.log
    exit 1
fi

login=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/admin/login)
echo "GET /admin/login -> $login"
[ "$login" = "200" ]

if [ "$#" -gt 0 ]; then
    BASE_URL=http://127.0.0.1:8000 "$@"
fi
