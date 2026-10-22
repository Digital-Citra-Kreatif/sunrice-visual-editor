// The part of Inertia's React adapter the editor uses: the panel's own copy,
// which build.mjs points `@inertiajs/react` at (window.Sunrice.modules).
declare module '@inertiajs/react' {
    type ComponentType<P> = import('react').ComponentType<P>;
    type ReactNode = import('react').ReactNode;

    export const Head: ComponentType<{ title?: string; children?: ReactNode }>;
    /** `onBefore` answering false keeps the visit from being made. */
    export const Link: ComponentType<{ href: string; className?: string; title?: string; onBefore?: () => boolean | void; children?: ReactNode }>;
    export const router: {
        visit(url: string, options?: Record<string, unknown>): void;
        on(type: string, listener: (event: { detail: { visit?: { method?: string } } }) => void): () => void;
    };
}
