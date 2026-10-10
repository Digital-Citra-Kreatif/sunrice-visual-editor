#!/usr/bin/env bash
# run.sh <name> <command…>   run a step, keep its output in .ci/<name>.log
# run.sh --report            repeat the end of each failed step's output as an error annotation
set -uo pipefail
mkdir -p .ci

if [ "${1:-}" = "--report" ]; then
    for failed in .ci/*.failed; do
        [ -e "$failed" ] || continue
        name=$(basename "$failed" .failed)
        # The end of the output is what says why; annotations are cut off after a few kilobytes.
        body=$(sed -e 's/\x1b\[[0-9;]*[A-Za-z]//g' ".ci/$name.log" | tr -d '\r' | tail -c 3000 | sed -e 's/%/%25/g' | awk '{printf "%s%%0A", $0}')
        echo "::error title=$name failed::$body"
    done
    exit 0
fi

name=$1
shift
"$@" 2>&1 | tee ".ci/$name.log"
status=${PIPESTATUS[0]}
[ "$status" -eq 0 ] || touch ".ci/$name.failed"
exit "$status"
