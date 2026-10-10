/**
 * What someone typed in the address field, as a path on this site, or null
 * when it points anywhere else. The frame only ever shows the site itself.
 */
export function sitePath(input: string, origin: string): string | null {
    const text = input.trim();
    if (text === '') {
        return '/';
    }

    let url: URL;
    try {
        url = new URL(text.startsWith('/') || /^[a-z][a-z0-9+.-]*:/i.test(text) ? text : `/${text}`, origin);
    } catch {
        return null;
    }
    if (url.origin !== origin) {
        return null;
    }

    return url.pathname + url.search;
}
