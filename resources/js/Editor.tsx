import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { editorAddress, isEditorAddress, type ManifestFile, type Measured, type Mode } from './bridge/protocol';
import { Outlines, type Outline } from './canvas/Outlines';
import { ApiError, copyToSite, listTemplates, readTemplate, type TemplateData, type TemplateInfo } from './lib/api';
import { useBridge } from './lib/frame';
import { pageOf, rememberPage } from './lib/memory';
import { isAdminPath, sitePath } from './lib/path';
import { pageTemplate, sameTemplate, shortName, type TemplateName } from './lib/templates';
import { TemplatePanel, type Loaded } from './panels/TemplatePanel';
import { TemplatesPanel } from './panels/TemplatesPanel';

type Props = {
    /** Path the editor opens on, e.g. "/" or "/about". */
    startPath: string;
};

/** The page in the frame, as its bridge described it. */
type Page = { url: string; title: string; files: ManifestFile[] };

/** How long a page gets to say it is ready after it has loaded. */
const READY_WITHIN = 1500;

const message = (error: unknown) => (error instanceof ApiError || error instanceof Error ? error.message : 'Something went wrong.');

/**
 * The editor's screen: the page being edited in a frame, the templates that
 * drew it on the left, and the template that is open on the right.
 *
 * The page is rendered for the editor (`?sve=1`): its elements say which
 * template they came from, and a script in it (the bridge) tells this
 * screen what the pointer is over. Everything drawn over the page is drawn
 * here, above the frame.
 */
export function Editor({ startPath }: Props) {
    const frame = useRef<HTMLIFrameElement>(null);
    // Set once: the frame is sent on to other pages by show(), not by rendering a new address.
    const [firstAddress] = useState(() => editorAddress(startPath, window.location.href) ?? '/?sve=1');

    const [address, setAddress] = useState(startPath);
    const [problem, setProblem] = useState<string | null>(null);
    const [mode, setMode] = useState<Mode>('select');

    const [page, setPage] = useState<Page | null>(null);
    /** loading: a page is on its way. unknown: one arrived that the editor can't follow. */
    const [waiting, setWaiting] = useState<'loading' | 'unknown' | null>('loading');
    const awaited = useRef(true);
    /** A template to open once the page that is on its way has arrived. */
    const wanted = useRef<TemplateName | null>(null);

    const [open, setOpen] = useState<TemplateName | null>(null);
    const [template, setTemplate] = useState<Loaded<TemplateData>>({ state: 'idle' });
    const [all, setAll] = useState<TemplateInfo[] | null>(null);
    const [copying, setCopying] = useState(false);

    /** The key of the template under the pointer: in the frame, or in the list. */
    const [hovered, setHovered] = useState<string | null>(null);
    const [pointed, setPointed] = useState<string | null>(null);
    const [measured, setMeasured] = useState<Measured | null>(null);

    const tell = useBridge(frame, (said) => {
        if (said.type === 'ready') {
            const files = said.manifest.files;
            awaited.current = false;
            setWaiting(null);
            setPage({ url: said.url, title: said.title, files });
            setAddress(said.url);
            setProblem(null);
            rememberPage(files.map((file) => `${file.origin}:${file.path}`), said.url);

            // The template asked for; else the one that was open, if it is here too; else the page's own.
            const still = open !== null && files.some((file) => sameTemplate(file, open));
            const own = pageTemplate(files);
            setOpen(wanted.current ?? (still ? open : own === null ? null : { path: own.path, origin: own.origin }));
            wanted.current = null;
        } else if (said.type === 'hover') {
            setHovered(said.target?.mark.file ?? null);
        } else if (said.type === 'select') {
            const file = page?.files.find((candidate) => candidate.key === said.target.mark.file);
            if (file !== undefined) {
                setOpen({ path: file.path, origin: file.origin });
            }
        } else if (said.type === 'measured') {
            setMeasured(said.measured);
        } else if (said.type === 'leaving') {
            leave();
        }
    });

    /** The page in the frame is going: nothing known about it holds any more. */
    const leave = () => {
        awaited.current = true;
        setWaiting('loading');
        setPage(null);
        setHovered(null);
        setMeasured(null);
    };

    /** Sends the frame to a page of the site, as an editor render. */
    const show = useCallback((path: string) => {
        const next = editorAddress(path, window.location.href);
        if (next === null || frame.current === null) {
            return;
        }
        leave();
        frame.current.src = next;
    }, []);

    /** Where the frame is now; null when that is another site, whose address can't be read from here. */
    const addressInFrame = (): string | null => {
        try {
            return frame.current?.contentWindow?.location.href ?? 'about:blank';
        } catch {
            return null;
        }
    };

    /**
     * The frame finished loading something. A page drawn for the editor
     * has said so by now, or is about to; anything else is either a page
     * of the site that lost `sve=1` on the way (a form posted, a redirect)
     * and is asked for again with it, or a page the editor can't follow.
     */
    const loaded = () => {
        const href = addressInFrame();
        if (href === null) {
            // Another site: its address is not this one's to read.
            setWaiting('unknown');

            return;
        }
        if (href === 'about:blank') {
            return;
        }
        const asEditor = editorAddress(href, window.location.href);
        if (asEditor !== null && !isEditorAddress(href)) {
            frame.current?.contentWindow?.location.replace(asEditor);

            return;
        }
        window.setTimeout(() => {
            // Still waiting, and for this same page: it is not going to say anything.
            if (awaited.current && addressInFrame() === href) {
                setWaiting('unknown');
            }
        }, READY_WITHIN);
    };

    const go = (event: FormEvent) => {
        event.preventDefault();
        const next = sitePath(address, window.location.origin);
        if (next === null) {
            setProblem('The editor only opens pages of this site.');

            return;
        }
        if (isAdminPath(next, Sunrice.adminUrl())) {
            setProblem('That is the admin. The editor opens pages of the site itself.');

            return;
        }
        setProblem(null);
        setAddress(next);
        show(next);
    };

    /** Opens a template; one that isn't on this page brings the page it was last seen on. */
    const openTemplate = (name: TemplateName) => {
        const here = page?.files.some((file) => sameTemplate(file, name)) ?? false;
        const seenOn = here ? null : pageOf(`${name.origin}:${name.path}`);
        if (seenOn !== null && seenOn !== page?.url) {
            wanted.current = name;
            show(seenOn);
        }
        setOpen(name);
    };

    const copy = (path: string) => {
        setCopying(true);
        copyToSite(path)
            .then((made) => {
                Sunrice.toast.success(`Copied to ${made.path}.`);
                // The site uses the copy from now on: draw the page again and open it.
                wanted.current = { path: made.path, origin: 'site' };
                setAll(null);
                if (page !== null) {
                    show(page.url);
                } else {
                    setOpen(wanted.current);
                }
            })
            .catch((error: unknown) => Sunrice.toast.error(message(error)))
            .finally(() => setCopying(false));
    };

    // Every template there is, for the list of those not on this page.
    useEffect(() => {
        if (all !== null) {
            return;
        }
        let stale = false;
        listTemplates()
            .then((templates) => !stale && setAll(templates))
            .catch(() => !stale && setAll([]));

        return () => {
            stale = true;
        };
    }, [all]);

    // The open template, read in full.
    useEffect(() => {
        if (open === null) {
            setTemplate({ state: 'idle' });

            return;
        }
        let stale = false;
        setTemplate({ state: 'loading' });
        readTemplate(open.path, open.origin)
            .then((data) => !stale && setTemplate({ state: 'ready', data }))
            .catch((error: unknown) => !stale && setTemplate({ state: 'failed', error: message(error) }));

        return () => {
            stale = true;
        };
    }, [open?.path, open?.origin]);

    const openFile = open === null ? null : (page?.files.find((file) => sameTemplate(file, open)) ?? null);
    const lit = hovered ?? pointed;
    const watched = useMemo(() => [...new Set([openFile?.key, lit].filter((key): key is string => typeof key === 'string'))], [openFile?.key, lit]);

    // A page that has just arrived starts in Select mode and watches nothing: tell it how things are.
    useEffect(() => {
        if (page !== null) {
            tell({ type: 'mode', mode });
        }
    }, [page, mode, tell]);
    useEffect(() => {
        if (page !== null) {
            tell({ type: 'watch', watch: { marks: [], files: watched } });
        }
    }, [page, watched, tell]);

    const outlines: Outline[] = [];
    if (page !== null && measured !== null) {
        for (const [index, key] of measured.watch.files.entries()) {
            const file = page.files.find((candidate) => candidate.key === key);
            // An answer to an earlier question may still be on its way: draw only what is asked for now.
            if (file !== undefined && watched.includes(key)) {
                outlines.push({ id: key, kind: key === lit ? 'hover' : 'open', label: shortName(file.path), rects: measured.files[index] ?? [] });
            }
        }
    }

    return (
        <div className="sve-shell">
            <Sunrice.inertia.Head title={page === null ? 'Visual editor' : `${page.title} · Visual editor`} />

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

                <div className="sve-modes" role="group" aria-label="What a click on the page does">
                    <button type="button" className="sve-modes__mode" aria-pressed={mode === 'select'} onClick={() => setMode('select')} title="A click on the page picks what was clicked">
                        Select
                    </button>
                    <button type="button" className="sve-modes__mode" aria-pressed={mode === 'browse'} onClick={() => setMode('browse')} title="Links and forms on the page work as usual">
                        Browse
                    </button>
                </div>

                <a className="sve-bar__link" href={page?.url ?? startPath} target="_blank" rel="noreferrer">
                    View page ↗
                </a>
            </header>

            <TemplatesPanel files={page?.files ?? null} waiting={waiting} all={all} open={open} onOpen={openTemplate} onPoint={setPointed} />

            <main className={`sve-canvas sve-canvas--${mode}`}>
                <div className="sve-stage">
                    <iframe ref={frame} className="sve-frame" src={firstAddress} title="Page being edited" onLoad={loaded} />
                    <Outlines outlines={outlines} />
                </div>
            </main>

            <TemplatePanel
                open={open}
                template={template}
                page={page === null ? null : pageTemplate(page.files)}
                onPage={openFile !== null}
                seenOn={open === null || openFile !== null ? null : pageOf(`${open.origin}:${open.path}`)}
                copying={copying}
                onOpen={openTemplate}
                onShowPage={(path) => {
                    wanted.current = open;
                    show(path);
                }}
                onCopy={copy}
            />
        </div>
    );
}
