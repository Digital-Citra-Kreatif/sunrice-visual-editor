// Builds the editor's admin script and stylesheet, the frame's bridge script and the code editor into dist/.
//
// The script runs inside the Sunrice panel and must use the panel's own
// React and Inertia (a second copy breaks hooks, and wouldn't see the
// panel's pages). As @imajiner/admin-sdk's Vite plugin does, any import of
// those modules, ours or a bundled library's, resolves to the panel's copy
// on window.Sunrice.modules (the panel's runtime, version 3).
import { build } from 'esbuild';
import { copyFile, mkdir } from 'node:fs/promises';

// What the editor and its libraries import from each module the panel shares (SHARED_MODULES in @imajiner/admin-sdk/shared).
const shared = {
    react: [
        'Children', 'Component', 'Fragment', 'Profiler', 'PureComponent', 'StrictMode', 'Suspense',
        'cloneElement', 'createContext', 'createElement', 'createRef', 'forwardRef', 'isValidElement',
        'lazy', 'memo', 'startTransition', 'use', 'useActionState', 'useCallback', 'useContext',
        'useDebugValue', 'useDeferredValue', 'useEffect', 'useId', 'useImperativeHandle',
        'useInsertionEffect', 'useLayoutEffect', 'useMemo', 'useOptimistic', 'useReducer', 'useRef',
        'useState', 'useSyncExternalStore', 'useTransition', 'version',
    ],
    'react/jsx-runtime': ['Fragment', 'jsx', 'jsxs'],
    'react-dom': ['createPortal', 'flushSync', 'unstable_batchedUpdates', 'version'],
    '@inertiajs/react': ['Head', 'Link', 'router', 'usePage'],
};

const shim = (name) => `const s = globalThis.Sunrice;
if (!s || !s.modules) throw new Error('[visual editor] This script needs the Sunrice admin panel (window.Sunrice is missing).');
const m = s.modules[${JSON.stringify(name)}];
export default m;
${shared[name].map((each) => `export const ${each} = m.${each};`).join('\n')}
`;

const panelModules = {
    name: 'panel-modules',
    setup(build) {
        build.onResolve({ filter: /^(react|react-dom|@inertiajs\/react)(\/.*)?$/ }, (args) => ({ path: args.path, namespace: 'panel' }));
        build.onLoad({ filter: /.*/, namespace: 'panel' }, (args) => {
            const name = args.path === 'react/jsx-dev-runtime' ? 'react/jsx-runtime' : args.path;
            if (shared[name] !== undefined) {
                return { contents: shim(name), loader: 'js' };
            }

            return { errors: [{ text: `The panel shares no "${args.path}": add it to build.mjs if it is in @imajiner/admin-sdk's SHARED_MODULES.` }] };
        });
    },
};

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
// the site's own pages, not in the admin: no React, nothing global.
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
