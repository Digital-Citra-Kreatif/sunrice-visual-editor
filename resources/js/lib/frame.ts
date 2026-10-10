/**
 * The editor's end of the conversation with the page in its frame (see
 * bridge/protocol.ts).
 */
import { useCallback, useEffect, useRef, type RefObject } from 'react';
import { unwrap, wrap, type FromPage, type ToPage } from '../bridge/protocol';

/**
 * Listens to the bridge in the frame and returns a way to talk to it.
 * Only messages from that frame's own window, on this site, are heard.
 */
export function useBridge(frame: RefObject<HTMLIFrameElement | null>, onMessage: (message: FromPage) => void): (message: ToPage) => void {
    // The listener is set up once and always calls the newest handler.
    const handler = useRef(onMessage);
    handler.current = onMessage;

    useEffect(() => {
        const listen = (event: MessageEvent) => {
            if (event.origin !== window.location.origin || event.source !== frame.current?.contentWindow) {
                return;
            }
            const message = unwrap<FromPage>(event.data);
            if (message !== null) {
                handler.current(message);
            }
        };
        window.addEventListener('message', listen);

        return () => window.removeEventListener('message', listen);
    }, [frame]);

    return useCallback(
        (message: ToPage) => {
            frame.current?.contentWindow?.postMessage(wrap(message), window.location.origin);
        },
        [frame],
    );
}
