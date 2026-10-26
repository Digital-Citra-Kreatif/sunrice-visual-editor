// The part of Inertia's React adapter the editor uses: the panel's own copy, shared on
// window.Sunrice.modules (build.mjs), so its types are written here rather than installed.
declare module '@inertiajs/react' {
    /** Inertia's link. `onBefore` answering false keeps the visit from being made. */
    export const Link: import('react').ComponentType<{ href: string; className?: string; title?: string; onBefore?: () => boolean | void; children?: import('react').ReactNode }>;
    export const Head: import('react').ComponentType<{ title?: string; children?: import('react').ReactNode }>;
    export const router: {
        visit(url: string, options?: Record<string, unknown>): void;
    };
}

