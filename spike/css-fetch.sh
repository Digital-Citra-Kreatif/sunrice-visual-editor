#!/usr/bin/env bash
# Fetches the stylesheets written by other people that `php spike/css.php`
# reads when they are there, and the CSS library it compares with, each at
# the version the results were written for:
#
#   spike/css-fetch.sh            into ../deps (or $SPIKE_DEPS)
#
# Only what is missing is fetched.
set -euo pipefail

deps="${SPIKE_DEPS:-$(cd "$(dirname "$0")/../.." && pwd)/deps}"
mkdir -p "$deps"

fetch() {
    local name="$1" repo="$2" tag="$3"
    if [ -d "$deps/$name/.git" ]; then
        echo "$name: already there"
        return
    fi
    git clone --quiet --depth 1 --branch "$tag" "https://github.com/$repo.git" "$deps/$name"
    echo "$name: $tag"
}

fetch normalize.css necolas/normalize.css 8.0.1
fetch pico picocss/pico v2.0.6
fetch bulma jgthms/bulma 1.0.2
fetch bootstrap twbs/bootstrap v5.3.3
fetch PHP-CSS-Parser MyIntervals/PHP-CSS-Parser v8.9.0
