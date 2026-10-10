import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { editorAddress, isEditorAddress, markValue, type ManifestFile, type Mark, type Measured, type Mode } from './bridge/protocol';
import { NoticeBar, type Notice } from './canvas/Notice';
import { Outlines, type Outline } from './canvas/Outlines';
import { ApiError, applyOperations, copyToSite, listInserts, listTemplates, readTemplate, type Insertable, type Operation, type TemplateData, type TemplateInfo } from './lib/api';
import { useBridge } from './lib/frame';
import { pageOf, rememberPage } from './lib/memory';
import { isAdminPath, sitePath } from './lib/path';
import { pageTemplate, sameTemplate, shortName, type TemplateName } from './lib/templates';
import { boxesOf, findNode, rowFor } from './lib/tree';
import { CodePanel } from './panels/CodePanel';
import { InsertPanel } from './panels/InsertPanel';
import { LayersPanel, nodeTitle } from './panels/LayersPanel';
import { Side } from './panels/Panel';
import { SettingsPanel } from './panels/SettingsPanel';
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
 * The editor's screen: the page being edited in a frame, its templates and
 * the layers of the open one on the left, and the open template on the
 * right.
 *
 * The page is rendered for the editor (`?sve=1`): its elements say which
 * template and which node they came from, and a script in it (the bridge)
 * tells this screen what the pointer is over. Everything drawn over the
 * page is drawn here, above the frame.
 */
export function Editor({ startPath }: Props) {
    const frame = useRef<HTMLIFrameElement>(null);
    // Set once: the frame is sent on to other pages by show(), not by rendering a new address.
    const [firstAddress] = useState(() => editorAddress(startPath, window.location.href) ?? '/?sve=1');

    /** The address of the page in the frame, and what is being typed in its place, if anything. */
    const [address, setAddress] = useState(startPath);
    const [typed, setTyped] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [mode, setMode] = useState<Mode>('select');

    const [page, setPage] = useState<Page | null>(null);
    /** loading: a page is on its way. unknown: one arrived that the editor can't follow. */
    const [waiting, setWaiting] = useState<'loading' | 'unknown' | null>('loading');
    /** Whether the page in the frame has said it is one drawn for the editor, since it was last asked. */
    const answered = useRef(false);
    /** The address of the last page that was in the frame, to know the same page drawn again from another. */
    const lastPage = useRef<string | null>(null);
    /** A template to open once the page that is on its way has arrived. */
    const wanted = useRef<TemplateName | null>(null);

    const [open, setOpen] = useState<TemplateName | null>(null);
    const [template, setTemplate] = useState<Loaded<TemplateData>>({ state: 'idle' });
    /** Bumped to read the open template from disk again. */
    const [readings, setReadings] = useState(0);
    const [coding, setCoding] = useState(false);
    /** The open template's text as changed in the code panel and not saved; null when it is as on disk. */
    const [draft, setDraft] = useState<string | null>(null);
    const unsaved = draft !== null;
    const [all, setAll] = useState<TemplateInfo[] | null>(null);
    const [copying, setCopying] = useState(false);
    /** A change from the Settings panel is on its way to the server. */
    const [applying, setApplying] = useState(false);
    /** How many changes the server has refused. */
    const [refusals, setRefusals] = useState(0);
    /** What there is to tell: the last refusal, or the last thing done out of sight. */
    const [notice, setNotice] = useState<Notice | null>(null);
    const say = (kind: Notice['kind'], text: string) => setNotice({ kind, text, at: Date.now() });
    /** The page in the frame is being drawn again, not left: what is known about it holds until it is back. */
    const redrawing = useRef(false);
    /** Once the page is back, bring what is selected into view: it may be new, and anywhere. */
    const revealing = useRef(false);

    /** The left side lists what can be inserted, in place of the page's templates. */
    const [inserting, setInserting] = useState(false);
    const [inserts, setInserts] = useState<Loaded<Insertable[]>>({ state: 'idle' });

    /** The selected node of the open template, by its id in the tree. */
    const [selected, setSelected] = useState<string | null>(null);
    /** A node to select once the template it is in has been opened and read. */
    const toSelect = useRef<{ template: TemplateName; id: string } | null>(null);
    const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set());

    /** What the pointer is over: an element of the page, a row of the template list, a row of the layers. */
    const [hovered, setHovered] = useState<Mark | null>(null);
    const [pointedFile, setPointedFile] = useState<string | null>(null);
    const [pointedNode, setPointedNode] = useState<string | null>(null);
    const [measured, setMeasured] = useState<Measured | null>(null);

    const tree = template.state === 'ready' ? template.data.tree : null;
    const openFile = open === null ? null : (page?.files.find((file) => sameTemplate(file, open)) ?? null);

    const tell = useBridge(frame, (said) => {
        if (said.type === 'ready') {
            const files = said.manifest.files;
            answered.current = true;
            redrawing.current = false;
            setWaiting(null);
            setPage({ url: said.url, title: said.title, files });
            setAddress(said.url);
            setProblem(null);
            rememberPage(files.map((file) => `${file.origin}:${file.path}`), said.url);

            // The template asked for. Else the one that was open: on the same page it stays
            // whatever it is, and on another page it stays if it is there too, or has changes
            // waiting to be saved. Else the page's own.
            const still = open !== null && (unsaved || said.url === lastPage.current || files.some((file) => sameTemplate(file, open)));
            lastPage.current = said.url;
            const own = pageTemplate(files);
            const next = wanted.current ?? (still ? open : own === null ? null : { path: own.path, origin: own.origin });
            wanted.current = null;
            if (!sameTemplate(next, open)) {
                setSelected(null);
            }
            setOpen(next);
        } else if (said.type === 'hover') {
            setHovered(said.target?.mark ?? null);
        } else if (said.type === 'select') {
            if (applying) {
                // A change is being saved: what is selected next is for the tree that comes back.
                return;
            }
            const { file: key, id } = said.target.mark;
            const file = page?.files.find((candidate) => candidate.key === key);
            if (file === undefined) {
                return;
            }
            if (sameTemplate(file, open)) {
                // An element of the open template: select it.
                if (findNode(tree, id) !== null) {
                    setSelected(id);
                }
            } else {
                // An element of another template: open that one, and select the element once it is read.
                toSelect.current = { template: { path: file.path, origin: file.origin }, id };
                openTemplate({ path: file.path, origin: file.origin });
            }
        } else if (said.type === 'measured') {
            setMeasured(said.measured);
        } else if (said.type === 'leaving') {
            if (!redrawing.current) {
                leave();
            }
        }
    });

    /** The page in the frame is going: nothing known about it holds any more. */
    const leave = () => {
        setWaiting('loading');
        setPage(null);
        setHovered(null);
        setMeasured(null);
        // The address field shows where the frame is going, not what was being typed for the page before.
        setTyped(null);
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

    /**
     * Draws the page in the frame again, after a template changed. The
     * panels keep what they show until the new page says what it is.
     */
    const redraw = () => {
        const inFrame = frame.current?.contentWindow;
        if (page === null || inFrame === null || inFrame === undefined) {
            return;
        }
        redrawing.current = true;
        try {
            inFrame.location.reload();
        } catch {
            redrawing.current = false;
            show(page.url);
        }
    };

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
        // Whatever was said before may have come from the page that was here a moment ago:
        // ask the one that has loaded. A page drawn for the editor answers at once.
        answered.current = false;
        tell({ type: 'hello' });
        window.setTimeout(() => {
            if (!answered.current && addressInFrame() === href) {
                setWaiting('unknown');
                setPage(null);
                setHovered(null);
                setMeasured(null);
            }
        }, READY_WITHIN);
    };

    const go = (event: FormEvent) => {
        event.preventDefault();
        const next = sitePath(typed ?? address, window.location.origin);
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
        if (sameTemplate(open, name)) {
            return;
        }
        if (unsaved && !window.confirm(`Leave ${open?.path ?? 'this template'} without saving what you changed in its code?`)) {
            toSelect.current = null;

            return;
        }
        if (toSelect.current !== null && !sameTemplate(toSelect.current.template, name)) {
            // An element clicked for a template that is no longer where the editor is going.
            toSelect.current = null;
        }
        setDraft(null);
        setSelected(null);
        setPointedNode(null);
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
                say('done', `Copied to ${made.path}.`);
                // The site uses the copy from now on: draw the page again and open it.
                wanted.current = { path: made.path, origin: 'site' };
                setAll(null);
                if (page !== null) {
                    show(page.url);
                } else {
                    setOpen(wanted.current);
                }
            })
            .catch((error: unknown) => say('problem', message(error)))
            .finally(() => setCopying(false));
    };

    /** Selects a layer and brings what shows it on the page into view. */
    const select = (id: string) => {
        if (applying) {
            return;
        }
        setSelected(id);
        const [first] = boxesOf(tree, id).ids;
        if (first !== undefined && openFile !== null) {
            tell({ type: 'reveal', mark: { file: openFile.key, id: first } });
        }
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
        setCollapsed(new Set());
        readTemplate(open.path, open.origin)
            .then((data) => {
                if (stale) {
                    return;
                }
                setTemplate({ state: 'ready', data });
                // The element that was clicked to get here. One clicked for another template is
                // not this reading's to take: the template that was open may still be arriving
                // when the click sends the editor on.
                const waitingFor = toSelect.current;
                if (waitingFor !== null && sameTemplate(waitingFor.template, open)) {
                    toSelect.current = null;
                    if (findNode(data.tree, waitingFor.id) !== null) {
                        setSelected(waitingFor.id);
                    }
                }
            })
            .catch((error: unknown) => !stale && setTemplate({ state: 'failed', error: message(error) }));

        return () => {
            stale = true;
        };
    }, [open?.path, open?.origin, readings]);

    // Closing the tab with changes in the code panel: the browser asks first.
    useEffect(() => {
        if (!unsaved) {
            return;
        }
        const ask = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', ask);

        return () => window.removeEventListener('beforeunload', ask);
    }, [unsaved]);

    /** The code panel wrote the file: show it as it is now, in the panels and on the page. */
    const saved = (data: TemplateData) => {
        setTemplate({ state: 'ready', data });
        say('done', `Saved ${data.path}.`);
        setDraft(null);
        // The ids of the old tree mean nothing in the new one.
        setSelected(null);
        setCollapsed(new Set());
        // Whether it opens as a tree may have changed with it.
        setAll(null);
        redraw();
    };

    /**
     * Sends changes from the Settings or the Insert panel: the file is
     * saved, the page drawn again, and the node the last change made or
     * touched selected. Answers whether the changes were made.
     */
    const apply = (operations: Operation[]): Promise<boolean> => {
        if (open === null || template.state !== 'ready' || applying) {
            return Promise.resolve(false);
        }
        setApplying(true);

        return applyOperations(open.path, template.data.version, operations)
            .then(({ template: changed, focus }) => {
                setTemplate({ state: 'ready', data: changed });
                // The page shows that it worked. What was wrong with the change before it no longer is.
                setNotice(null);
                // The ids of the old tree mean nothing in the new one.
                setSelected(rowFor(changed.tree, focus));
                setPointedNode(null);
                setCollapsed(new Set());
                revealing.current = true;
                redraw();

                return true;
            })
            .catch((error: unknown) => {
                say('problem', message(error));
                setRefusals((count) => count + 1);
                if (error instanceof ApiError && error.reason === 'conflict') {
                    // The file is not what the panels show any more: read it again.
                    setSelected(null);
                    setReadings((count) => count + 1);
                    redraw();
                }

                return false;
            })
            .finally(() => setApplying(false));
    };

    // What can be inserted, asked for each time the list is opened: the site's partials and content change.
    useEffect(() => {
        if (!inserting) {
            return;
        }
        let stale = false;
        setInserts((before) => (before.state === 'ready' ? before : { state: 'loading' }));
        listInserts()
            .then((data) => !stale && setInserts({ state: 'ready', data }))
            .catch((error: unknown) => !stale && setInserts((before) => (before.state === 'ready' ? before : { state: 'failed', error: message(error) })));

        return () => {
            stale = true;
        };
    }, [inserting]);

    // --- What is shown on the page -------------------------------------------------

    /** The node under the pointer: a row of the layers, or an element of the open template on the page. */
    const litNode = pointedNode ?? (hovered !== null && hovered.file === openFile?.key && findNode(tree, hovered.id) !== null ? hovered.id : null);
    /** The template under the pointer, when it is not the open one: a row of the list, or an element of another template. */
    const litFile = pointedFile ?? (hovered !== null && hovered.file !== openFile?.key ? hovered.file : null);

    const selectedBoxes = useMemo(() => boxesOf(tree, selected), [tree, selected]);
    const litBoxes = useMemo(() => boxesOf(tree, litNode === selected ? null : litNode), [tree, litNode, selected]);
    const watch = useMemo(() => {
        const key = openFile?.key;
        const ids = key === undefined ? [] : [...new Set([...selectedBoxes.ids, ...litBoxes.ids])];

        return {
            marks: ids.map((id) => ({ file: key ?? '', id })),
            files: [...new Set([key, litFile].filter((file): file is string => typeof file === 'string'))],
        };
    }, [openFile?.key, selectedBoxes, litBoxes, litFile]);

    // A page that has just arrived starts in Select mode and watches nothing: tell it how things are.
    useEffect(() => {
        if (page !== null) {
            tell({ type: 'mode', mode });
        }
    }, [page, mode, tell]);
    useEffect(() => {
        if (page !== null) {
            tell({ type: 'watch', watch });
        }
    }, [page, watch, tell]);
    // The page is back after a change: what the change made may be out of sight.
    useEffect(() => {
        const [first] = selectedBoxes.ids;
        if (page !== null && revealing.current) {
            revealing.current = false;
            if (first !== undefined && openFile !== null) {
                tell({ type: 'reveal', mark: { file: openFile.key, id: first } });
            }
        }
    }, [page]);

    const selectedNode = findNode(tree, selected);
    const outlines: Outline[] = [];
    if (page !== null && measured !== null) {
        // An answer to an earlier question may still be on its way: draw only what is asked for now.
        const rectsOf = (ids: string[]) =>
            ids.flatMap((id) => {
                const at = measured.watch.marks.findIndex((mark) => mark.file === openFile?.key && mark.id === id);

                return at === -1 ? [] : (measured.marks[at] ?? []);
            });
        for (const [index, key] of measured.watch.files.entries()) {
            const file = page.files.find((candidate) => candidate.key === key);
            if (file !== undefined && watch.files.includes(key) && (key === litFile || (selected === null && litNode === null))) {
                outlines.push({ id: key, kind: key === litFile ? 'hover' : 'open', label: shortName(file.path), rects: measured.files[index] ?? [] });
            }
        }
        if (selectedNode !== null) {
            outlines.push({ id: `selected:${markValue({ file: openFile?.key ?? '', id: selectedNode.id })}`, kind: selectedBoxes.inside ? 'around' : 'selected', label: nodeTitle(selectedNode), rects: rectsOf(selectedBoxes.ids) });
        }
        const lit = findNode(tree, litNode === selected ? null : litNode);
        if (lit !== null) {
            outlines.push({ id: `lit:${lit.id}`, kind: 'hover', label: nodeTitle(lit), rects: rectsOf(litBoxes.ids) });
        }
    }

    const layersNote =
        open === null
            ? 'No template is open.'
            : template.state === 'loading' || template.state === 'idle'
              ? 'Opening…'
              : template.state === 'failed'
                ? 'The template could not be opened.'
                : template.data.tree === null
                  ? 'This template can only be edited as code, so it has no layers to show.'
                  : null;

    const codeUnsaved = 'The code of this template has changes that are not saved. Save or revert them first.';
    const insertLocked =
        template.state !== 'ready'
            ? (layersNote ?? 'No template is open.')
            : !template.data.editable
              ? 'This is one of Sunrice’s own views: nothing can be put into it here.'
              : tree === null
                ? 'This template can only be edited as code.'
                : unsaved
                  ? codeUnsaved
                  : null;

    return (
        <div className="sve-shell" aria-busy={applying}>
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
                        value={typed ?? address}
                        onChange={(event) => setTyped(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                setTyped(null);
                                setProblem(null);
                            }
                        }}
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

                <button type="button" className="sve-button sve-button--quiet" aria-pressed={inserting} onClick={() => setInserting((on) => !on)} title="Add elements, Blade and components to the open template">
                    <span aria-hidden="true">+</span> Insert
                </button>

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

            <Side side="left">
                {inserting ? (
                    <InsertPanel
                        inserts={inserts.state === 'ready' ? inserts.data : null}
                        failed={inserts.state === 'failed' ? inserts.error : null}
                        tree={tree}
                        selected={selectedNode?.id ?? null}
                        locked={insertLocked}
                        busy={applying}
                        onInsert={(operation) => apply([operation])}
                        onClose={() => setInserting(false)}
                    />
                ) : (
                    <TemplatesPanel files={page?.files ?? null} waiting={waiting} all={all} open={open} onOpen={openTemplate} onPoint={setPointedFile} />
                )}
                <LayersPanel
                    tree={tree}
                    note={layersNote}
                    selected={selected}
                    lit={litNode}
                    collapsed={collapsed}
                    onSelect={select}
                    onPoint={setPointedNode}
                    onToggle={(id) =>
                        setCollapsed((before) => {
                            const after = new Set(before);
                            if (!after.delete(id)) {
                                after.add(id);
                            }

                            return after;
                        })
                    }
                />
            </Side>

            <main className={`sve-canvas sve-canvas--${mode}`}>
                <div className="sve-stage">
                    <iframe ref={frame} className="sve-frame" src={firstAddress} title="Page being edited" onLoad={loaded} />
                    <Outlines outlines={outlines} />
                    <NoticeBar notice={notice} onDismiss={() => setNotice(null)} />
                </div>
                {coding && template.state === 'ready' && (
                    <CodePanel
                        template={template.data}
                        draft={draft}
                        onDraft={setDraft}
                        onSaved={saved}
                        onReload={() => {
                            setDraft(null);
                            setSelected(null);
                            setReadings((count) => count + 1);
                        }}
                        onClose={() => setCoding(false)}
                    />
                )}
            </main>

            <Side side="right">
                {template.state === 'ready' && selectedNode !== null && (
                    <SettingsPanel
                        template={template.data}
                        node={selectedNode}
                        busy={applying}
                        refusals={refusals}
                        locked={unsaved ? codeUnsaved : null}
                        onApply={apply}
                        onDeselect={() => setSelected(null)}
                    />
                )}
                <TemplatePanel
                    open={open}
                    template={template}
                    page={page === null ? null : pageTemplate(page.files)}
                    onPage={openFile !== null}
                    seenOn={open === null || openFile !== null ? null : pageOf(`${open.origin}:${open.path}`)}
                    copying={copying}
                    coding={coding}
                    unsaved={unsaved}
                    grow={selectedNode === null}
                    onCode={() => setCoding((on) => !on)}
                    onOpen={openTemplate}
                    onShowPage={(path) => {
                        wanted.current = open;
                        show(path);
                    }}
                    onCopy={copy}
                />
            </Side>
        </div>
    );
}
