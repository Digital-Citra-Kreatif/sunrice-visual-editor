// Builds the editor's admin script and stylesheet, the frame's bridge script and the code editor into dist/.
//
// The script is a plugin bundle of the Sunrice panel (runtime version 3) and
// must use the panel's own React and Inertia (a second React breaks hooks;
// a second Inertia doesn't see the panel's pages). As @sunrice/admin-sdk's
// Vite plugin does, every import of a module the panel shares resolves to
// its copy on window.Sunrice.modules: ours, and a bundled library's.
import { build } from 'esbuild';
import { copyFile, mkdir } from 'node:fs/promises';

/** The modules the panel shares (@sunrice/admin-sdk's SHARED_MODULES), as far as the editor imports them. */
const shared = ['react', 'react/jsx-runtime', 'react-dom', 'react-dom/client', '@inertiajs/react'];

const panelModules = {
    name: 'panel-modules',
    setup(build) {
        build.onResolve({ filter: /^(react|react-dom|@inertiajs\/react)(\/.*)?$/ }, (args) => ({ path: args.path, namespace: 'panel-modules' }));
        build.onLoad({ filter: /.*/, namespace: 'panel-modules' }, (args) => {
            const name = args.path === 'react/jsx-dev-runtime' ? 'react/jsx-runtime' : args.path;
            if (!shared.includes(name)) {
                return { errors: [{ text: `The panel shares no "${args.path}": import it from one it does, or add it to build.mjs.` }] };
            }

            return {
                contents: [
                    'const s = globalThis.Sunrice;',
                    "if (!s) throw new Error('[sunrice-visual-editor] This script runs only inside the Sunrice admin panel (window.Sunrice is missing).');",
                    `const m = s.modules[${JSON.stringify(name)}];`,
                    'export default m;',
                    // Every name the module has, so `import { useState } from 'react'` reads the panel's.
                    ...namesOf(name).map((key) => `export const ${key} = m.${key};`),
                ].join('\n'),
                loader: 'js',
            };
        });
    },
};

/** The names each shared module exports, as @sunrice/admin-sdk lists them. */
function namesOf(name) {
    return {
        react: [
            'Activity', 'Children', 'Component', 'Fragment', 'Profiler', 'PureComponent', 'StrictMode', 'Suspense',
            'cloneElement', 'createContext', 'createElement', 'createRef', 'forwardRef', 'isValidElement', 'lazy', 'memo',
            'startTransition', 'use', 'useActionState', 'useCallback', 'useContext', 'useDebugValue', 'useDeferredValue',
            'useEffect', 'useEffectEvent', 'useId', 'useImperativeHandle', 'useInsertionEffect', 'useLayoutEffect', 'useMemo',
            'useOptimistic', 'useReducer', 'useRef', 'useState', 'useSyncExternalStore', 'useTransition', 'version',
        ],
        'react/jsx-runtime': ['Fragment', 'jsx', 'jsxs'],
        'react-dom': ['createPortal', 'flushSync', 'unstable_batchedUpdates', 'version'],
        'react-dom/client': ['createRoot', 'hydrateRoot', 'version'],
        '@inertiajs/react': ['Head', 'Link', 'router', 'usePage'],
    }[name];
}

await mkdir('dist', { recursive: true });

await build({
    entryPoints: ['resources/js/index.tsx'],
    outfile: 'dist/visual-editor.js',
    bundle: true,
    format: 'esm',
    target: 'es2022',
    minify: true,
    legalComments: 'none',
    jsx: 'automatic',
    plugins: [panelModules],
    logLevel: 'info',
});

// The script loaded in a page rendered for the editor's frame. It runs in
// the site's own pages, not in the panel: no React, nothing global.
await build({
    entryPoints: ['resources/js/bridge/index.ts'],
    outfile: 'dist/bridge.js',
    bundle: true,
    format: 'iife',
    target: 'es2020',
    minify: true,
    legalComments: 'none',
    logLevel: 'info',
});

// The code editor (CodeMirror), fetched the first time code is shown: it is
// larger than the rest of the editor together, and the admin does not carry
// it on every page. No React in it.
await build({
    entryPoints: ['resources/js/code/index.ts'],
    outfile: 'dist/code-editor.js',
    bundle: true,
    format: 'esm',
    target: 'es2022',
    minify: true,
    legalComments: 'none',
    logLevel: 'info',
});

await copyFile('resources/css/visual-editor.css', 'dist/visual-editor.css');
