# ci/lock

A scratch branch, not part of the package. It lets everything in the package
be run on a machine that can reach GitHub but not Packagist or the npm
registry, which is where much of this package is written.

- `composer.lock` is resolved by CI from `main`'s `composer.json` (on PHP 8.3)
  and committed here by `.github/workflows/lock.yml`. To have it made again
  after `composer.json` changes, or to take newer versions, push any commit to
  this branch: `git commit --allow-empty -m "Lock again" && git push`.
- `vendor_from_lock.py` fetches every package in that lock from GitHub (one
  shallow `git fetch` each: 157 packages, under a minute) and writes a lock
  whose packages install from those copies.
- `setup.sh` runs it, then `composer install`, then puts together a
  `node_modules/` from build tools the machine already has and React's types
  from GitHub.

```bash
git clone --depth 1 --branch ci/lock https://github.com/littleboy130491/sunrice-visual-editor lock
git clone https://github.com/littleboy130491/sunrice-visual-editor
lock/setup.sh sunrice-visual-editor
cd sunrice-visual-editor
composer test
```

## The editor in a browser

```bash
mkdir -p .ci
composer workbench                                   # the demo site: templates, database, content
vendor/bin/testbench serve --port=8089 > .ci/serve.log 2>&1 &

# The demo site serves a copy of dist/ made when it was built. Serve dist/ itself, so a new build is what is tested:
site=vendor/orchestra/testbench-core/laravel
rm -rf $site/public/vendor/sunrice-visual-editor && ln -s "$PWD/dist" $site/public/vendor/sunrice-visual-editor

npm run build
BROWSER_CHANNEL=chromium BASE_URL=http://127.0.0.1:8089 node tests/Browser/editor.mjs
BROWSER_CHANNEL=chromium npm run test:bridge
```

`BROWSER_CHANNEL=chromium` is for a machine with Playwright's Chromium and no
Chrome. A run that fails part way leaves the demo site's templates as the
failed step left them; put them back with:

```bash
cp -r vendor/sunrice/cms/stubs/templates/. vendor/orchestra/testbench-core/laravel/resources/views/sunrice/
```

The browser check rewrites `docs/screenshots/editor.png`: `git checkout docs/screenshots` before committing, CI makes its own.
