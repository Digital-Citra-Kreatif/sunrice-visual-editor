import { useEffect, useState, type RefObject } from 'react';

/** The room an element has, in CSS pixels, kept up as it changes; null until it has been measured. */
export function useSize(element: RefObject<HTMLElement | null>): { width: number; height: number } | null {
    const [size, setSize] = useState<{ width: number; height: number } | null>(null);

    useEffect(() => {
        const measured = element.current;
        if (measured === null) {
            return;
        }
        const take = () => {
            const box = measured.getBoundingClientRect();
            setSize((before) => (before !== null && before.width === box.width && before.height === box.height ? before : { width: box.width, height: box.height }));
        };
        take();
        const observer = new ResizeObserver(take);
        observer.observe(measured);

        return () => observer.disconnect();
    }, [element]);

    return size;
}
