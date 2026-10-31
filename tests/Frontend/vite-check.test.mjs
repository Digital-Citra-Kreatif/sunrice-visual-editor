import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtemp, mkdir, readFile, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

test('the actual Vite CSS probes drain a large matching response under pipefail', async () => {
    const script = await readFile(new URL('../../.github/vite-check.sh', import.meta.url), 'utf8');
    const directory = await mkdtemp(join(tmpdir(), 'sve-css-pipe-'));
    try {
        await mkdir(join(directory, 'resources/css'), { recursive: true });
        const css = join(directory, 'resources/css/app.css');
        // grep sees the match before curl/cat finish writing their output.
        await writeFile(css, '.bg-lime-300{} .bg-fuchsia-500{} .bg-teal-600{}\n' + 'x'.repeat(2_000_000));
        const devProbe = script.split('\n').find((line) => line.includes('if curl -gsS'));
        const builtProbes = script.split('\n').filter((line) => line.startsWith('built | grep '));
        assert.ok(devProbe);
        assert.equal(builtProbes.length, 2);
        for (const probe of [devProbe, ...builtProbes]) {
            const result = spawnSync('bash', ['-c', `set -euo pipefail\ndev="$1"\nfound=\ncss_file="$2"\nbuilt() { cat "$css_file"; }\nfail() { exit 9; }\n${probe}\n${probe === devProbe ? 'test "$found" = yes' : ''}`, 'probe', `file://${directory}`, css], { encoding: 'utf8' });
            assert.equal(result.status, 0, result.stderr);
        }
        await writeFile(css, 'x'.repeat(2_000_000));
        const missing = spawnSync('bash', ['-c', `set -euo pipefail\ndev="$1"\nfound=\n${devProbe}\ntest -z "$found"`, 'probe', `file://${directory}`], { encoding: 'utf8' });
        assert.equal(missing.status, 0, missing.stderr);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});
