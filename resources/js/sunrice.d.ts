// The part of window.Sunrice (the Sunrice panel's runtime for plugin
// bundles, version 3) that the editor uses, and the modules the panel
// shares with it (build.mjs). See packages/admin/docs/frontend.md in
// sunrice: @imajiner/admin-sdk's definePlugin() and adminUrl() do the same.
import type * as ReactNS from 'react';

type PageComponent = ReactNS.ComponentType<any> & {
    /** true: no admin layout (sidebar, header) around the page. */
    bare?: boolean;
};

interface SunriceRuntime {
    version: number;
    modules: Record<string, Record<string, unknown>>;
    registerPage(name: string, component: PageComponent): void;
    /** 'visual-editor' → '/admin/visual-editor', with the panel's path. */
    adminUrl(path?: string): string;
}

declare global {
    const Sunrice: SunriceRuntime;

    interface Window {
        Sunrice: SunriceRuntime;
    }
}

export {};
