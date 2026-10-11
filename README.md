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

The demo site is built into the same Laravel skeleton the tests run in
(`vendor/orchestra/testbench-core/laravel`): once it is built there, the tests
that expect a site without templates fail. So the site is served from a second
directory with its own `vendor/`, and everything else in it a link to the clone:

```bash
repo=$PWD                                             # the clone, set up as above
mkdir ../serve && cd ../serve
for each in src config routes resources dist workbench testbench.yaml composer.json; do ln -s "$repo/$each" "$each"; done
cp "$repo/composer.lock" composer.lock
COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MIRROR_PATH_REPOS=1 COMPOSER_DISABLE_NETWORK=1 composer install --no-interaction
mkdir -p .ci
composer workbench                                    # templates, database, content

# Serve the clone's dist/ itself, not the copy made when the site was built: a new build is then what is tested.
site=vendor/orchestra/testbench-core/laravel
rm -rf $site/public/vendor/sunrice-visual-editor && ln -s "$repo/dist" $site/public/vendor/sunrice-visual-editor
nohup vendor/bin/testbench serve --port=8089 > .ci/serve.log 2>&1 &

cd "$repo"
npm run build
BROWSER_CHANNEL=chromium BASE_URL=http://127.0.0.1:8089 node tests/Browser/editor.mjs
BROWSER_CHANNEL=chromium npm run test:bridge
```

`BROWSER_CHANNEL=chromium` is for a machine with Playwright's Chromium and no
Chrome. A run that fails part way leaves the demo site's templates as the
failed step left them; put them back, from `../serve`, with:

```bash
cp -r vendor/sunrice/cms/stubs/templates/. vendor/orchestra/testbench-core/laravel/resources/views/sunrice/
```

The browser check rewrites `docs/screenshots/editor.png`: `git checkout docs/screenshots` before committing, CI makes its own.

If `composer test` fails in `EditorRenderTest` on a page that should have no template of the site's, the demo site was built in the clone itself: `rm -rf vendor` and install again.
