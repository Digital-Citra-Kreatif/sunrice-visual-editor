// A small callback harness for the actual React components. It assigns frame
// refs before effects and retains hook state across renders; no DOM assertions.
let active;

export function harness(component, props) {
    const state = { slots: [], effects: [], cursor: 0, props, output: null };
    state.render = () => {
        active = state;
        state.cursor = 0;
        state.output = component(state.props);
        active = null;
        const attach = (node) => {
            if (!node || typeof node !== 'object') return;
            if (node.type === 'iframe' && node.props.ref && !node.props.ref.current) {
                node.props.ref.current = { src: node.props.src, contentWindow: { location: { href: 'http://localhost/?sve=1' }, postMessage() {} } };
            }
            for (const child of [node.props?.children].flat(Infinity)) attach(child);
        };
        attach(state.output);
        for (const effect of state.effects.splice(0)) effect();
        return state.output;
    };
    return state;
}

export const Fragment = Symbol('Fragment');
export const jsx = (type, props) => ({ type, props });
export const jsxs = jsx;
export function useState(initial) {
    const state = active;
    const index = state.cursor++;
    if (!(index in state.slots)) state.slots[index] = typeof initial === 'function' ? initial() : initial;
    return [state.slots[index], (value) => { state.slots[index] = typeof value === 'function' ? value(state.slots[index]) : value; }];
}
export function useRef(value) { return useState(() => ({ current: value }))[0]; }
const equal = (a, b) => a && b && a.length === b.length && a.every((value, index) => Object.is(value, b[index]));
export function useMemo(fn, deps) {
    const state = active;
    const index = state.cursor++;
    if (!state.slots[index] || !equal(deps, state.slots[index].deps)) state.slots[index] = { deps, value: fn() };
    return state.slots[index].value;
}
export const useCallback = (fn, deps) => useMemo(() => fn, deps);
export function useEffect(fn, deps) {
    const state = active;
    const index = state.cursor++;
    const before = state.slots[index];
    if (!before || !equal(deps, before.deps)) {
        state.slots[index] = { deps };
        state.effects.push(() => { before?.cleanup?.(); state.slots[index].cleanup = fn(); });
    }
}
export const useLayoutEffect = useEffect;
