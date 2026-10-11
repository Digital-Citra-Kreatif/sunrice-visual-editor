// Asks a browser about the stylesheets and the changes of `php spike/css.php`:
//
//   node spike/css-browser.mjs          (BROWSER_CHANNEL=chromium for Playwright's own)
//
// 1. Is every rule the browser has for a file one of those found, in the
//    same order? The browser is shown the file with a mark put at the start
//    of every block found (`--sve-i: 17`), and says by the marks which of
//    its rules is which. Rules it drops (a selector it does not know) have
//    no say; rules it has without a mark were not found.
// 2. After each change, has exactly one rule changed for the browser, the
//    one that was meant, and has it changed as meant?
//
// It reads spike/css-plan.json, written by the PHP run, and writes
// spike/css-browser.json, which the next PHP run puts into CSS_RESULTS.md.
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const here = new URL('.', import.meta.url);
const plan = JSON.parse(await readFile(new URL('css-plan.json', here), 'utf8'));
const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL ?? 'chrome' });
const page = await browser.newPage();
await page.setContent('<!doctype html><title>css</title>');

const results = [];
for (const file of plan) {
    const source = await readFile(file.path);

    const result = await page.evaluate(
        ({ bytes, rules, edits }) => {
            const source = Uint8Array.from(bytes);
            const text = (from) => new TextDecoder().decode(from);
            /** Every rule that styles something, in the order of the file: its selector and its own declarations. */
            const listed = (css) => {
                const sheet = new CSSStyleSheet();
                sheet.replaceSync(css);
                const list = [];
                const walk = (all) => {
                    for (const rule of all) {
                        if (rule instanceof CSSStyleRule) {
                            list.push({ selector: rule.selectorText, own: rule.style.cssText, style: rule.style });
                        }
                        if (rule.cssRules && !(rule instanceof CSSKeyframesRule)) {
                            walk(rule.cssRules);
                        }
                    }
                };
                walk(sheet.cssRules);

                return list;
            };

            const splice = (into, at, remove, insert) => {
                const put = new TextEncoder().encode(insert);
                const out = new Uint8Array(into.length - remove + put.length);
                out.set(into.subarray(0, at), 0);
                out.set(put, at);
                out.set(into.subarray(at + remove), at + put.length);

                return out;
            };

            const before = listed(text(source));

            // 1. The file with a mark at the start of every block found, the last first so the places hold.
            let marked = source;
            for (let index = rules.length - 1; index >= 0; index--) {
                marked = splice(marked, rules[index].at, 0, `--sve-i:${index};`);
            }
            const examples = [];
            /** For each rule found, which of the browser's it is; undefined when the browser has none for it. */
            const inBrowser = new Map();
            let last = -1;
            let inOrder = true;
            let unfound = 0;
            const withMarks = listed(text(marked));
            if (withMarks.length !== before.length) {
                inOrder = false;
                examples.push(`the browser has ${before.length} rules, and ${withMarks.length} once the blocks found are marked`);
            }
            withMarks.forEach((rule, index) => {
                const mark = rule.style.getPropertyValue('--sve-i').trim();
                if (mark === '') {
                    unfound++;
                    examples.length < 6 && examples.push(`the browser has "${rule.selector}", which was not found`);

                    return;
                }
                const mine = Number(mark);
                if (mine <= last) {
                    inOrder = false;
                    examples.length < 6 && examples.push(`"${rule.selector}" comes before a rule it was found after`);
                }
                last = mine;
                inBrowser.set(mine, index);
            });

            // 2. Each change, as the browser sees it.
            let right = 0;
            let unseen = 0;
            let wrong = 0;
            for (const edit of edits) {
                const after = listed(text(splice(source, edit.at, edit.remove, edit.insert)));
                const say = (what) => examples.length < 8 && examples.push(`${edit.kind} ${edit.property} in "${edit.rule}": ${what}`);
                if (after.length !== before.length) {
                    wrong++;
                    say(`the browser has ${after.length} rules after it, ${before.length} before`);

                    continue;
                }
                const differing = [];
                for (let index = 0; index < before.length; index++) {
                    if (before[index].selector !== after[index].selector || before[index].own !== after[index].own) {
                        differing.push(index);
                    }
                }
                const expected = inBrowser.get(edit.index);
                if (differing.length === 0) {
                    // A rule or a property the browser does not know: it never had the declaration, and has none now.
                    // Or a declaration that another of the same rule overrides either way.
                    unseen++;
                } else if (differing.length > 1 || differing[0] !== expected) {
                    wrong++;
                    say(differing.length > 1 ? `${differing.length} rules changed for the browser` : `the rule that changed is "${after[differing[0]].selector}"`);
                } else {
                    const rule = after[differing[0]];
                    const value = rule.style.getPropertyValue(edit.property).trim();
                    // A shorthand set beside its own longhands has no value of its own to ask for: the rule's text says.
                    const meant = edit.kind === 'set' ? value === 'inherit' || rule.own.includes('inherit') : edit.kind === 'add' ? value === '1' : true;
                    if (meant) {
                        right++;
                    } else {
                        wrong++;
                        say(`it has ${edit.property}: ${value || '(nothing)'}`);
                    }
                }
            }

            return { mine: rules.length, browser: before.length, inOrder, unfound, edits: edits.length, right, unseen, wrong, examples };
        },
        { bytes: [...source], rules: file.rules, edits: file.edits },
    );

    results.push({ file: file.file, ...result });
    console.log(`${file.file}: the browser has ${result.browser} of the ${result.mine} rules found (${result.unfound} of its own not found, in order: ${result.inOrder}); changes ${result.right} right, ${result.unseen} not seen, ${result.wrong} wrong`);
    for (const example of result.examples) {
        console.log(`    ${example}`);
    }
}

await writeFile(new URL('css-browser.json', here), JSON.stringify({ browser: `${browser.browserType().name()} ${browser.version()}`, files: results }, null, 1));
await browser.close();
process.exit(results.some((result) => !result.inOrder || result.unfound > 0 || result.wrong > 0) ? 1 : 0);
