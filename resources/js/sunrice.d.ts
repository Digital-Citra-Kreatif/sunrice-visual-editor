// The part of window.Sunrice (the Sunrice admin's extension API, version 2)
// that the editor uses. See sunrice-cms docs/packages.md.
import type * as ReactNS from 'react';
import type * as ReactDOMNS from 'react-dom';

type PageComponent = ReactNS.ComponentType<any> & {
    /** null: no admin layout (sidebar, header) around the page. */
    layout?: ((page: ReactNS.ReactNode) => ReactNS.ReactNode) | null;
};

interface SunriceApi {
    version: number;
    React: typeof ReactNS;
    ReactDOM: typeof ReactDOMNS;
    registerPage(name: string, component: PageComponent): void;
    inertia: {
        router: { visit(url: string, options?: Record<string, unknown>): void };
        Link: ReactNS.ComponentType<{ href: string; className?: string; title?: string; children?: ReactNS.ReactNode }>;
        Head: ReactNS.ComponentType<{ title?: string; children?: ReactNS.ReactNode }>;
        usePage(): { props: Record<string, unknown> };
    };
    /** 'visual-editor' → '/cms/visual-editor', with the site's admin path. */
    adminUrl(path?: string): string;
    fetchJson<T = unknown>(url: string, init?: RequestInit): Promise<T>;
    toast: { success(message: string): void; error(message: string): void };
}

declare global {
    const Sunrice: SunriceApi;

    interface Window {
        Sunrice: SunriceApi;
    }

    // JSX compiles to Sunrice.React.createElement (tsconfig.json), so
    // TypeScript looks for the JSX types globally: they are React's.
    namespace JSX {
        type ElementType = ReactNS.JSX.ElementType;
        type Element = ReactNS.JSX.Element;
        type ElementClass = ReactNS.JSX.ElementClass;
        type ElementAttributesProperty = ReactNS.JSX.ElementAttributesProperty;
        type ElementChildrenAttribute = ReactNS.JSX.ElementChildrenAttribute;
        type LibraryManagedAttributes<C, P> = ReactNS.JSX.LibraryManagedAttributes<C, P>;
        type IntrinsicAttributes = ReactNS.JSX.IntrinsicAttributes;
        type IntrinsicClassAttributes<T> = ReactNS.JSX.IntrinsicClassAttributes<T>;
        type IntrinsicElements = ReactNS.JSX.IntrinsicElements;
    }
}

export {};
