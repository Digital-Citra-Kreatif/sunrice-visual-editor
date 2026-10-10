import { useRef, useState, type FormEvent } from 'react';
import { sitePath } from './lib/path';
import { Panel } from './panels/Panel';

type Props = {
    /** Path the editor opens on, e.g. "/" or "/about". */
    startPath: string;
};

/**
 * The editor's screen: an address bar over the page being edited, with the
 * page's templates on the left and the selection's settings on the right.
 * The panels are filled in by later tasks (EXECUTION_PLAN.md, phases 3–5).
 */
export function Editor({ startPath }: Props) {
    const [path, setPath] = useState(startPath);
    const [address, setAddress] = useState(startPath);
    const [problem, setProblem] = useState<string | null>(null);
    const frame = useRef<HTMLIFrameElement>(null);

    const go = (event: FormEvent) => {
        event.preventDefault();
        const next = sitePath(address, window.location.origin);
        if (next === null) {
            setProblem('The editor only opens pages of this site.');

            return;
        }
        setProblem(null);
        setAddress(next);
        if (next === path) {
            frame.current?.contentWindow?.location.reload();
        } else {
            setPath(next);
        }
    };

    return (
        <div className="sve-shell">
            <Sunrice.inertia.Head title="Visual editor" />

            <header className="sve-bar">
                <Sunrice.inertia.Link href={Sunrice.adminUrl()} className="sve-bar__back" title="Back to the admin">
                    <span aria-hidden="true">←</span> Admin
                </Sunrice.inertia.Link>
                <strong className="sve-bar__title">Visual editor</strong>

                <form className="sve-address" onSubmit={go}>
                    <label className="sve-address__label" htmlFor="sve-address">
                        Page
                    </label>
                    <input
                        id="sve-address"
                        className="sve-address__input"
                        value={address}
                        onChange={(event) => setAddress(event.target.value)}
                        spellCheck={false}
                        autoComplete="off"
                        aria-invalid={problem !== null}
                        aria-describedby={problem !== null ? 'sve-address-problem' : undefined}
                    />
                    <button className="sve-button" type="submit">
                        Open
                    </button>
                    {problem !== null && (
                        <p className="sve-address__problem" id="sve-address-problem" role="alert">
                            {problem}
                        </p>
                    )}
                </form>

                <a className="sve-bar__link" href={path} target="_blank" rel="noreferrer">
                    View page ↗
                </a>
            </header>

            <Panel title="Templates on this page" side="left">
                <p className="sve-empty">The Blade files that draw this page will be listed here.</p>
            </Panel>

            <main className="sve-canvas">
                <iframe ref={frame} className="sve-frame" src={path} title="Page being edited" />
            </main>

            <Panel title="Settings" side="right">
                <p className="sve-empty">Nothing is selected.</p>
            </Panel>
        </div>
    );
}
