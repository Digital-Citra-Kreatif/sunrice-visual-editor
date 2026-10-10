// Builds the editor's admin script and stylesheet into dist/.
//
// The script runs inside the Sunrice admin and must use the admin's own
// React (a second copy breaks hooks). JSX compiles to
// Sunrice.React.createElement, and any `react` / `react-dom` import, ours
// or a bundled library's, resolves to the copies on window.Sunrice.
import { build } from 'esbuild';
import { copyFile, mkdir } from 'node:fs/promises';

const reactNames = [
    'Children', 'Component', 'Fragment', 'Profiler', 'PureComponent', 'StrictMode', 'Suspense',
    'cloneElement', 'createContext', 'createElement', 'createRef', 'forwardRef', 'isValidElement',
    'lazy', 'memo', 'startTransition', 'use', 'useActionState', 'useCallback', 'useContext',
    'useDebugValue', 'useDeferredValue', 'useEffect', 'useId', 'useImperativeHandle',
    'useInsertionEffect', 'useLayoutEffect', 'useMemo', 'useOptimistic', 'useReducer', 'useRef',
    'useState', 'useSyncExternalStore', 'useTransition', 'version',
];
const reactDomNames = ['createPortal', 'flushSync', 'unstable_batchedUpdates', 'version'];

const shim = (global, names) => `const m = ${global};\nexport default m;\n${names.map((name) => `export const ${name} = m.${name};`).join('\n')}\n`;

const adminReact = {
    name: 'admin-react',
    setup(build) {
        build.onResolve({ filter: /^react(-dom)?(\/.*)?$/ }, (args) => ({ path: args.path, namespace: 'admin-react' }));
        build.onLoad({ filter: /.*/, namespace: 'admin-react' }, (args) => {
            if (args.path === 'react') {
                return { contents: shim('window.Sunrice.React', reactNames), loader: 'js' };
            }
            if (args.path === 'react-dom') {
                return { contents: shim('window.Sunrice.ReactDOM', reactDomNames), loader: 'js' };
            }
            if (args.path === 'react/jsx-runtime' || args.path === 'react/jsx-dev-runtime') {
                return {
                    contents: `const R = window.Sunrice.React;
export const Fragment = R.Fragment;
const element = (type, { children, ...props }, key) => {
    const all = key === undefined ? props : { ...props, key };
    return Array.isArray(children) ? R.createElement(type, all, ...children) : children === undefined ? R.createElement(type, all) : R.createElement(type, all, children);
};
export const jsx = element;
export const jsxs = element;
export const jsxDEV = element;
`,
                    loader: 'js',
                };
            }

            return { errors: [{ text: `No admin copy of "${args.path}": import it from window.Sunrice or add it to build.mjs.` }] };
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
    jsx: 'transform',
    jsxFactory: 'Sunrice.React.createElement',
    jsxFragment: 'Sunrice.React.Fragment',
    plugins: [adminReact],
    logLevel: 'info',
});

await copyFile('resources/css/visual-editor.css', 'dist/visual-editor.css');
