import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { editorAddress, isEditorAddress, markValue, shortcutOf, unwrap, type FromPage, type ManifestFile, type Mark, type Measured, type Mode, type Shortcut } from './bridge/protocol';
import { NoticeBar, type Notice } from './canvas/Notice';
import { Outlines, type Outline } from './canvas/Outlines';
import { ApiError, applyOperations, changeStyles, copyToSite, linkStylesheet, listFields, listInserts, listTemplates, readSite, readStyles, readTemplate, saveSource, saveStylesheet, type Insertable, type Operation, type PickVariable, type Site, type Styles, type StylesSaved, type TemplateData, type TemplateInfo, type ThemeFile } from './lib/api';
import { useBridge } from './lib/frame';
import { canRedo, canUndo, recorded, redoStep, redone, undoStep, undone, type History } from './lib/history';
import { pageOf, rememberPage } from './lib/memory';
import { startRedraw, type Redraw } from './lib/redraw';
import { actionOn, actionsFor, moveTo, type Action } from './lib/move';
import { isAdminPath, sitePath } from './lib/path';
import { classesOf } from './lib/settings';
import { STYLE_PROPERTIES } from './lib/styleFields';
import { layoutOf, resolve, sheetsOf, shiftedId, type StyleEdit } from './lib/styles';
import { pageTemplate, sameTemplate, shortName, type TemplateName } from './lib/templates';
import { boxesOf, findNode, rowFor } from './lib/tree';
import { CodePanel } from './panels/CodePanel';
import { InsertPanel, type InsertTab } from './panels/InsertPanel';
import { LayersPanel, nodeTitle } from './panels/LayersPanel';
import { Side } from './panels/Panel';
import { CssRules } from './panels/CssPanel';
import { StyleFields } from './panels/StylePanel';
import { SettingsPanel, type SettingsTab } from './panels/SettingsPanel';
import { TemplatePanel, type Loaded } from './panels/TemplatePanel';
import { TemplatesPanel } from './panels/TemplatesPanel';

type Props = {
    /** Path the editor opens on, e.g. "/" or "/about". */
    startPath: string;
};

/** The page in the frame, as its bridge described it. `status` is what the server answered it with. */
type Page = { url: string; title: string; files: ManifestFile[]; status: number };

/** How long a page gets to say it is ready after it has loaded. */
const READY_WITHIN = 1500;
/** A page drawn again out of sight: how long it gets to finish loading once it is ready, and to arrive at all. */
const SETTLE_WITHIN = 250;
const REDRAWN_WITHIN = 10000;
/** How long the page that was replaced is kept, out of sight, before it is let go. */
const LET_GO_AFTER = 400;

const later = (after: number, run: () => void) => {
    const timer = window.setTimeout(run, after);

    return () => window.clearTimeout(timer);
};

/** The name a template's history goes by. */
const historyKey = (template: { origin: string; path: string }) => `${template.origin}:${template.path}`;

/** The name a stylesheet's history goes by. */
const sheetKey = (path: string) => `theme:${path}`;

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
    // Two frames, one above the other. `frame` is the one that is seen and talked to. `spare`
    // is where the page is drawn again after a change, out of sight: when it is ready the
    // two change places, so the page is never seen to go and come back.
    const frames = [useRef<HTMLIFrameElement>(null), useRef<HTMLIFrameElement>(null)] as const;
    const frame = useRef<HTMLIFrameElement | null>(null);
    const spare = useRef<HTMLIFrameElement | null>(null);
    /** Which of the two is seen: what their classes say. */
    const [front, setFront] = useState<0 | 1>(0);
    useLayoutEffect(() => {
        frame.current = frames[0].current;
        spare.current = frames[1].current;
    }, []);
    /** The page being drawn again in the spare frame, until it is shown or given up on. */
    const drawing = useRef<Redraw | null>(null);
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
    /** The wait for that answer: one at a time, for the page that loaded last. */
    const answerWait = useRef<number | null>(null);
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
    const say = (kind: Notice['kind'], text: string, action?: Notice['action']) => setNotice({ kind, text, at: Date.now(), action });

    /** Undo and redo, for each template changed since the editor was opened: by "origin:path". */
    const [histories, setHistories] = useState<Record<string, History>>({});
    /** A change was made and the page is being drawn again: what the page answered with before it. */
    const statusBefore = useRef<number | null>(null);
    /** What a shortcut does, as things are now: the keys are listened for once, and things change. */
    const shortcuts = useRef<(shortcut: Shortcut) => void>(() => {});
    /** The page in the frame is being drawn again, not left: what is known about it holds until it is back. */
    const redrawing = useRef(false);
    /** While it is, and until the new page has measured what is asked of it, the boxes over the page stay as they were. */
    const [holding, setHolding] = useState(false);
    const held = useRef<Outline[]>([]);
    const watching = useRef('');
    /** Once the page is back, bring what is selected into view: it may be new, and anywhere. */
    const revealing = useRef(false);

    /** For an element: its settings, or the rules of the stylesheets for its classes. */
    const [settingsTab, setSettingsTab] = useState<SettingsTab>('settings');
    /** The class whose rules are shown, when one was picked by hand. */
    const [pickedClass, setPickedClass] = useState<string | null>(null);
    /** What the theme's stylesheets say, about the class shown or about nothing in particular. */
    const [styles, setStyles] = useState<Styles | null>(null);
    const [stylesFailed, setStylesFailed] = useState<string | null>(null);
    /** Bumped to read the stylesheets again. */
    const [styleReadings, setStyleReadings] = useState(0);
    /** The same, for the changes that wait their turn: each is made on the stylesheets as the one before it left them. */
    const stylesNow = useRef<Styles | null>(null);
    const classNow = useRef<string | null>(null);
    const styleWork = useRef<Promise<void>>(Promise.resolve());
    /** Undo and redo for each stylesheet changed since the editor was opened, by its path. Not state: a change reads what the one before it wrote. */
    const sheetHistories = useRef<Record<string, History>>({});
    const [, setSheetSteps] = useState(0);
    /** The addresses of the stylesheets the page in the frame links; null until it has said. */
    const [linked, setLinked] = useState<string[] | null>(null);
    const [linking, setLinking] = useState(false);
    /** What the page has for the properties the Style tab shows, for the element it was asked about. */
    const [onPage, setOnPage] = useState<{ mark: string; values: Record<string, string> } | null>(null);

    /** The left side lists what can be inserted, in place of the page's templates. */
    const [inserting, setInserting] = useState(false);
    const [insertTab, setInsertTab] = useState<InsertTab>('library');
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
            const status = said.manifest.status ?? 200;
            setPage({ url: said.url, title: said.title, files, status });
            // A change that compiled can still fail where the template is drawn: a variable that
            // isn't there, a collection that doesn't exist. No check before saving can know that.
            const before = statusBefore.current;
            statusBefore.current = null;
            if (before !== null && before < 400 && status >= 400) {
                say('problem', `The page could not be drawn after that change: the server answered ${status}.`, { label: 'Undo', run: () => shortcuts.current('undo') });
            }
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
            if (!redrawing.current) {
                setHovered(said.target?.mark ?? null);
            }
        } else if (said.type === 'select') {
            if (applying || redrawing.current) {
                // A change is being saved, or the page drawn again after one: what is on the
                // page is the file as it was, and its elements are not those of the tree now.
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
        } else if (said.type === 'styles') {
            setLinked(said.linked);
        } else if (said.type === 'computed') {
            setOnPage(said.values === null ? null : { mark: markValue(said.mark), values: said.values });
        } else if (said.type === 'key') {
            shortcuts.current(said.key);
        } else if (said.type === 'measured') {
            setMeasured(said.measured);
            if (!redrawing.current && JSON.stringify(said.measured.watch) === watching.current) {
                setHolding(false);
            }
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
        setHolding(false);
        setLinked(null);
        setOnPage(null);
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
        // A page being drawn again for the one that is now being left is not wanted any more.
        drawing.current?.cancel();
        drawing.current = null;
        frame.current.src = next;
    }, []);

    /**
     * Draws the page in the frame again, after a template changed. The
     * panels keep what they show until the new page says what it is, and
     * so does the frame: the page is drawn in the spare frame, scrolled
     * to where the old one is, and the two then change places.
     */
    const redraw = () => {
        const shown = frame.current;
        const next = spare.current;
        const inFrame = shown?.contentWindow;
        if (page === null || shown === null || inFrame === null || inFrame === undefined) {
            return;
        }
        let href: string;
        let at: { left: number; top: number };
        try {
            href = inFrame.location.href;
            at = { left: inFrame.scrollX, top: inFrame.scrollY };
        } catch {
            show(page.url);

            return;
        }
        redrawing.current = true;
        setHolding(true);
        drawing.current?.cancel();
        if (next === null) {
            inFrame.location.reload();

            return;
        }

        const mine = startRedraw({
            settle: SETTLE_WITHIN,
            giveUp: REDRAWN_WITHIN,
            later,
            show: () => {
                try {
                    next.contentWindow?.scrollTo({ ...at, behavior: 'instant' });
                } catch {
                    // Not a page of this site: it is shown from its top.
                }
                // Once it has been drawn where it was scrolled to.
                window.requestAnimationFrame(() => {
                    if (drawing.current !== mine) {
                        return;
                    }
                    drawing.current = null;
                    const hadKeys = document.activeElement === shown;
                    frame.current = next;
                    spare.current = shown;
                    setFront((which) => (which === 0 ? 1 : 0));
                    if (hadKeys) {
                        // The keys went to the page: they go to the page still.
                        next.focus();
                    }
                    later(LET_GO_AFTER, () => {
                        if (spare.current === shown && drawing.current === null) {
                            shown.src = 'about:blank';
                        }
                    });
                    whenLoaded.current();
                });
            },
            fail: () => {
                // Nothing came of it: draw the page again where it is, as a browser does.
                drawing.current = null;
                next.src = 'about:blank';
                inFrame.location.reload();
            },
        });
        drawing.current = mine;
        next.src = href;
    };
    /** Something loaded in the frame that is not seen. Emptying it is a load too, and not the page awaited. */
    const spareLoaded = (loadedFrame: HTMLIFrameElement) => {
        let empty = false;
        try {
            empty = loadedFrame.contentWindow?.location.href === 'about:blank';
        } catch {
            // Another site's page: one all the same.
        }
        if (loadedFrame === spare.current && !empty) {
            drawing.current?.heard('load');
        }
    };
    /** What to do when a page has arrived in the frame that is seen: as things are now. */
    const whenLoaded = useRef(() => {});

    // The page being drawn again says it is ready, to this window: the bridge listens only to the frame that is seen.
    useEffect(() => {
        const heard = (event: MessageEvent) => {
            if (event.origin === window.location.origin && event.source === spare.current?.contentWindow && unwrap<FromPage>(event.data)?.type === 'ready') {
                drawing.current?.heard('ready');
            }
        };
        window.addEventListener('message', heard);

        return () => window.removeEventListener('message', heard);
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
        // Whatever was said before may have come from the page that was here a moment ago:
        // ask the one that has loaded. A page drawn for the editor answers at once.
        // The wait for the page before this one is over. Left running, it comes due while
        // this page is still being asked, finds no answer yet and says the page is gone:
        // a large page, read on the thread the timer runs on, is all that takes.
        if (answerWait.current !== null) {
            window.clearTimeout(answerWait.current);
        }
        answered.current = false;
        tell({ type: 'hello' });
        answerWait.current = window.setTimeout(() => {
            answerWait.current = null;
            if (!answered.current && addressInFrame() === href) {
                setWaiting('unknown');
                setPage(null);
                setHovered(null);
                setMeasured(null);
                setHolding(false);
                redrawing.current = false;
                if (statusBefore.current !== null) {
                    // It was a page of the editor's before the change, and is none now.
                    statusBefore.current = null;
                    say('problem', 'The page did not come back after that change.', { label: 'Undo', run: () => shortcuts.current('undo') });
                }
            }
        }, READY_WITHIN);
    };

    whenLoaded.current = loaded;

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

    // Undo and redo from the keyboard, wherever in the editor it is, except in a field, which has
    // its own. Delete is not listened for here: only on a layer, or on the page (the bridge).
    useEffect(() => {
        const pressed = (event: KeyboardEvent) => {
            const shortcut = event.defaultPrevented ? null : shortcutOf(event);
            if (shortcut === 'undo' || shortcut === 'redo') {
                event.preventDefault();
                shortcuts.current(shortcut);
            }
        };
        window.addEventListener('keydown', pressed);

        return () => window.removeEventListener('keydown', pressed);
    }, []);

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
        remember(data);
        setTemplate({ state: 'ready', data });
        say('done', `Saved ${data.path}.`);
        setDraft(null);
        // The ids of the old tree mean nothing in the new one.
        setSelected(null);
        setCollapsed(new Set());
        // Whether it opens as a tree may have changed with it.
        setAll(null);
        changedRedraw();
    };

    /** The open template was changed: its history gains the file as it was. */
    const remember = (changed: TemplateData) => {
        if (template.state !== 'ready' || template.data.source === null) {
            return;
        }
        const before = { source: template.data.source, selected };
        const was = template.data.version;
        setHistories((all) => ({ ...all, [historyKey(changed)]: recorded(all[historyKey(changed)], before, was, changed.version) }));
    };

    /** Draws the page again after a change, and remembers how it was doing before it. */
    const changedRedraw = () => {
        statusBefore.current = page?.status ?? null;
        redraw();
    };

    /**
     * Undo or redo: saves the open template as it was a step back, or a
     * step forward again, and selects what was selected then.
     */
    const travel = (direction: 'undo' | 'redo') => {
        if (open === null || template.state !== 'ready' || template.data.source === null || applying || unsaved) {
            return;
        }
        const now = template.data;
        const history = histories[historyKey(now)];
        const step = direction === 'undo' ? undoStep(history, now.version) : redoStep(history, now.version);
        if (history === undefined || step === null) {
            return;
        }
        const left = { source: template.data.source, selected };
        setApplying(true);
        saveSource(open.path, now.version, step.source)
            .then((data) => {
                setHistories((all) => ({ ...all, [historyKey(data)]: direction === 'undo' ? undone(history, left, data.version) : redone(history, left, data.version) }));
                setTemplate({ state: 'ready', data });
                setNotice(null);
                setSelected(rowFor(data.tree, step.selected));
                setPointedNode(null);
                setCollapsed(new Set());
                // Whether it opens as a tree may have changed with it.
                setAll(null);
                revealing.current = true;
                changedRedraw();
            })
            .catch((error: unknown) => {
                say('problem', message(error));
                if (error instanceof ApiError && error.reason === 'conflict') {
                    // The file has changed otherwise: read it again. Its history no longer applies.
                    setSelected(null);
                    setReadings((count) => count + 1);
                    redraw();
                }
            })
            .finally(() => setApplying(false));
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
                remember(changed);
                setTemplate({ state: 'ready', data: changed });
                // The page shows that it worked. What was wrong with the change before it no longer is.
                setNotice(null);
                // The ids of the old tree mean nothing in the new one.
                setSelected(rowFor(changed.tree, focus));
                setPointedNode(null);
                setCollapsed(new Set());
                revealing.current = true;
                changedRedraw();

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

    // What can be read at the selected node, for the Settings panel to fill a control from and to
    // say which field a value is. Asked for each node selected; what is known of the last holds meanwhile.
    const fieldsFor = template.state === 'ready' && template.data.editable && tree !== null && selected !== null && findNode(tree, selected) !== null ? `${template.data.path}\n${template.data.version}\n${selected}` : null;
    const [fieldsHere, setFieldsHere] = useState<{ asked: string; variables: PickVariable[] } | null>(null);
    useEffect(() => {
        if (fieldsFor === null || template.state !== 'ready' || selected === null) {
            return;
        }
        let stale = false;
        listFields(template.data.path, template.data.version, selected, false)
            .then((variables) => !stale && setFieldsHere({ asked: fieldsFor, variables }))
            .catch(() => undefined);

        return () => {
            stale = true;
        };
    }, [fieldsFor]);

    // The site's collections and taxonomies, for the controls that choose one: asked once.
    const [site, setSite] = useState<Site | null>(null);
    useEffect(() => {
        let stale = false;
        readSite()
            .then((read) => !stale && setSite(read))
            .catch(() => undefined);

        return () => {
            stale = true;
        };
    }, []);

    // --- The theme's stylesheets ------------------------------------------------------

    /** The element whose classes the CSS tab is about, and the class of it whose rules are shown. */
    const styled = template.state === 'ready' ? findNode(tree, selected) : null;
    const classes = styled !== null && styled.type === 'element' ? classesOf(styled) : null;
    const styleClass = classes === null || classes.length === 0 ? null : pickedClass !== null && classes.includes(pickedClass) ? pickedClass : classes[0];
    /** What is asked of the stylesheets: about the class, while its rules are shown; otherwise only which there are. */
    const askedClass = settingsTab === 'settings' ? null : styleClass;
    classNow.current = askedClass;

    const takeStyles = (read: Styles) => {
        if (read.class !== classNow.current) {
            // An answer about a class that is no longer the one shown: ask about the one that is.
            setStyleReadings((count) => count + 1);

            return;
        }
        stylesNow.current = read;
        setStyles(read);
        setStylesFailed(null);
    };

    useEffect(() => {
        let stale = false;
        readStyles(askedClass)
            .then((read) => !stale && takeStyles(read))
            .catch((error: unknown) => !stale && setStylesFailed(message(error)));

        return () => {
            stale = true;
        };
    }, [askedClass, styleReadings]);

    // The page shows the theme's stylesheets as they are now, and says which of them it links.
    const sheets = useMemo(() => sheetsOf(styles), [styles]);
    useEffect(() => {
        if (page !== null && styles !== null) {
            tell({ type: 'styles', sheets });
        }
    }, [page, sheets, tell]);

    /** A stylesheet was written: its history gains the file as it was. */
    const rememberSheet = (path: string, was: string, saved: StylesSaved, how: 'change' | 'undo' | 'redo') => {
        const all = sheetHistories.current;
        const history = all[sheetKey(path)];
        if (saved.before === null) {
            // Too large to keep copies of: nothing of it can be undone.
            delete all[sheetKey(path)];
        } else if (how === 'change' || history === undefined) {
            all[sheetKey(path)] = recorded(history, { source: saved.before, selected: null }, was, saved.file.version);
        } else {
            const left = { source: saved.before, selected: null };
            all[sheetKey(path)] = how === 'undo' ? undone(history, left, saved.file.version) : redone(history, left, saved.file.version);
        }
        setSheetSteps((count) => count + 1);
    };

    /** Runs when the changes before it are done: one stylesheet change at a time, each on the file as it is then. */
    const inTurn = (work: (now: Styles) => Promise<void>) => {
        const forClass = classNow.current;
        styleWork.current = styleWork.current.then(async () => {
            const now = stylesNow.current;
            if (now === null || now.class !== forClass || classNow.current !== forClass) {
                return;
            }
            try {
                await work(now);
                setNotice(null);
            } catch (error: unknown) {
                say('problem', message(error));
                // The rows show the file again, not what was typed.
                setRefusals((count) => count + 1);
                if (error instanceof ApiError && error.reason === 'conflict') {
                    setStyleReadings((count) => count + 1);
                }
            }
        });
    };

    /** A change from the CSS tab: the file is saved, and the page shows it at once. */
    const editStyle = (edit: StyleEdit) =>
        inTurn(async (now) => {
            const change = resolve(now, edit);
            if (change !== null) {
                const saved = await changeStyles(change.file.path, change.file.version, change.changes, now.class);
                rememberSheet(change.file.path, change.file.version, saved, 'change');
                takeStyles(saved.styles);
            }
        });

    const sheetFile = (now: Styles | null, path: string): ThemeFile | null => now?.files.find((file) => file.path === path) ?? (now?.own?.path === path ? now.own : null);

    /** Undo or redo in one stylesheet: saves it as it was a step back, or a step forward again. */
    const travelSheet = (path: string, direction: 'undo' | 'redo') =>
        inTurn(async (now) => {
            const file = sheetFile(now, path);
            const history = sheetHistories.current[sheetKey(path)];
            const step = file === null ? null : direction === 'undo' ? undoStep(history, file.version) : redoStep(history, file.version);
            if (file !== null && step !== null) {
                const saved = await saveStylesheet(path, file.version, step.source, now.class);
                rememberSheet(path, file.version, saved, direction);
                takeStyles(saved.styles);
            }
        });

    const sheetSteps = (path: string) => {
        const file = sheetFile(styles, path);
        const history = sheetHistories.current[sheetKey(path)];

        return { undo: file !== null && canUndo(history, file.version), redo: file !== null && canRedo(history, file.version) };
    };

    /** Links a stylesheet from the page's layout, and draws the page again with it. */
    const linkSheet = (stylesheet: string) => {
        const layout = layoutOf(page?.files ?? null);
        if (layout === null || linking) {
            return;
        }
        setLinking(true);
        readTemplate(layout.path, 'site')
            .then((read) =>
                linkStylesheet(layout.path, read.version, stylesheet).then(({ template: changed, before }) => {
                    setHistories((all) => ({ ...all, [historyKey(changed)]: recorded(all[historyKey(changed)], { source: before, selected: null }, read.version, changed.version) }));
                    if (sameTemplate(open, changed)) {
                        // The layout is the open template: what is selected in it has moved down by the link.
                        setTemplate({ state: 'ready', data: changed });
                        setSelected(rowFor(changed.tree, shiftedId(selected, before, changed.source ?? before)));
                        setPointedNode(null);
                        setCollapsed(new Set());
                    }
                    say('done', `Linked ${stylesheet} from ${layout.path}.`);
                    changedRedraw();
                }),
            )
            .catch((error: unknown) => say('problem', message(error)))
            .finally(() => setLinking(false));
    };

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

    // What the page has for the selected element, while the Style tab shows it: the first copy of it on the page.
    const styledMark = settingsTab === 'style' && openFile !== null && selectedBoxes.ids.length > 0 && !selectedBoxes.inside ? markValue({ file: openFile.key, id: selectedBoxes.ids[0] }) : null;
    useEffect(() => {
        if (page !== null) {
            tell({ type: 'computed', ask: styledMark === null || openFile === null ? null : { mark: { file: openFile.key, id: selectedBoxes.ids[0] }, properties: STYLE_PROPERTIES } });
        }
    }, [page, styledMark, tell]);

    watching.current = JSON.stringify(watch);

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

    if (!holding) {
        held.current = outlines;
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
    /** Why the open template can't be changed from the panels right now, when it can't. */
    const fixed =
        template.state !== 'ready'
            ? (layersNote ?? 'No template is open.')
            : !template.data.editable
              ? 'This is one of Sunrice’s own views: it can’t be changed here.'
              : tree === null
                ? 'This template can only be edited as code.'
                : unsaved
                  ? codeUnsaved
                  : null;

    /** Moves, copies, unwraps or deletes a node, from a button or a key. */
    const act = (id: string, action: Action) => {
        const operation = fixed === null ? actionOn(tree, id, action) : null;
        if (operation !== null) {
            void apply([operation]);
        }
    };

    const history = template.state === 'ready' ? histories[historyKey(template.data)] : undefined;
    const undoable = template.state === 'ready' && fixed === null && !applying && canUndo(history, template.data.version);
    const redoable = template.state === 'ready' && fixed === null && !applying && canRedo(history, template.data.version);

    shortcuts.current = (shortcut) => {
        if (shortcut === 'delete') {
            if (selected !== null) {
                act(selected, 'delete');
            }
        } else {
            travel(shortcut);
        }
    };

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

                <div className="sve-history" role="group" aria-label="Undo and redo">
                    <button type="button" className="sve-button sve-button--quiet" disabled={!undoable} onClick={() => travel('undo')} title="Undo the last change to this template (Ctrl+Z)">
                        Undo
                    </button>
                    <button type="button" className="sve-button sve-button--quiet" disabled={!redoable} onClick={() => travel('redo')} title="Redo what was undone (Ctrl+Shift+Z)">
                        Redo
                    </button>
                </div>

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
                        template={template.state === 'ready' ? template.data : null}
                        tab={insertTab}
                        onTab={setInsertTab}
                        inserts={inserts.state === 'ready' ? inserts.data : null}
                        failed={inserts.state === 'failed' ? inserts.error : null}
                        tree={tree}
                        selected={selectedNode?.id ?? null}
                        locked={fixed}
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
                    fixed={applying || fixed !== null}
                    onSelect={select}
                    onPoint={setPointedNode}
                    onMove={(id, drop) => void apply([moveTo(id, drop)])}
                    onAct={act}
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
                    {frames.map((one, which) => (
                        <iframe
                            key={which}
                            ref={one}
                            className={which === front ? 'sve-frame' : 'sve-spare'}
                            src={which === 0 ? firstAddress : undefined}
                            title={which === front ? 'Page being edited' : undefined}
                            aria-hidden={which === front ? undefined : true}
                            tabIndex={which === front ? undefined : -1}
                            onLoad={(event) => (event.currentTarget === frame.current ? loaded() : spareLoaded(event.currentTarget))}
                        />
                    ))}
                    <Outlines outlines={holding ? held.current : outlines} />
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
                        fields={fieldsHere !== null && fieldsHere.asked === fieldsFor ? fieldsHere.variables : null}
                        site={site}
                        busy={applying}
                        refusals={refusals}
                        locked={unsaved ? codeUnsaved : null}
                        can={actionsFor(tree, selectedNode.id)}
                        tab={settingsTab}
                        onTab={setSettingsTab}
                        styling={
                            selectedNode.type !== 'element'
                                ? null
                                : {
                                      style: (
                                          <StyleFields
                                              // A change that was refused starts every control afresh.
                                              key={`${selectedNode.id}:${refusals}`}
                                              classes={classes}
                                              chosen={styleClass}
                                              onChoose={setPickedClass}
                                              styles={styles}
                                              failed={stylesFailed}
                                              onPage={onPage !== null && onPage.mark === styledMark ? onPage.values : null}
                                              linked={linked}
                                              layout={layoutOf(page?.files ?? null)}
                                              linking={linking || unsaved}
                                              onEdit={editStyle}
                                              onLink={linkSheet}
                                          />
                                      ),
                                      css: (
                                          <CssRules
                                              key={`${selectedNode.id}:${refusals}`}
                                              classes={classes}
                                              chosen={styleClass}
                                              onChoose={setPickedClass}
                                              styles={styles}
                                              failed={stylesFailed}
                                              linked={linked}
                                              layout={layoutOf(page?.files ?? null)}
                                              linking={linking || unsaved}
                                              can={sheetSteps}
                                              onEdit={editStyle}
                                              onTravel={travelSheet}
                                              onLink={linkSheet}
                                          />
                                      ),
                                  }
                        }
                        onApply={apply}
                        onAct={(action) => act(selectedNode.id, action)}
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
