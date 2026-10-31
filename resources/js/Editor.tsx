import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type FormEvent, type PointerEvent as ReactPointerEvent } from 'react';
import { editorAddress, isEditorAddress, markValue, shortcutOf, unwrap, type FromPage, type ManifestFile, type Mark, type Measured, type Mode, type Shortcut, type ShownEntry } from './bridge/protocol';
import { NoticeBar, type Notice } from './canvas/Notice';
import { Outlines, type Outline } from './canvas/Outlines';
import { ApiError, applyOperations, copyToSite, fieldsAt, listTemplates, readTemplate, runBuild, saveSource, type Built, type FieldsAt, type Loaded, type Operation, type TemplateData, type TemplateInfo, type TreeNode } from './lib/api';
import { useBridge } from './lib/frame';
import { typedDraft, savedDraft, sameVersion, type Draft } from './lib/drafts';
import { canRedo, canUndo, recorded, redoStep, redone, undoStep, undone, type History } from './lib/history';
import { readLayout, shareOf, siding, writeLayout } from './lib/layout';
import { pageOf, rememberPage } from './lib/memory';
import { actionOn, moveTo, type Action } from './lib/move';
import { isAdminPath, sitePath } from './lib/path';
import { startRedraw, type Redraw } from './lib/redraw';
import { pageTemplate, sameTemplate, shortName, type TemplateName } from './lib/templates';
import { useSize } from './lib/size';
import { boxesOf, findNode, nodeAt, rowFor } from './lib/tree';
import { layout, readWidths, type View } from './lib/widths';
import { CodePanel, type CodePlace } from './panels/CodePanel';
import { ContentLink } from './panels/ContentLink';
import { CustomFilePanel } from './panels/CustomFilePanel';
import { LayersPanel, nodeTitle } from './panels/LayersPanel';
import { Pane, Side } from './panels/Panel';
import { TemplatesPanel } from './panels/TemplatesPanel';

type Props = {
    /** Path the editor opens on, e.g. "/" or "/about". */
    startPath: string;
    /** The devices the page can be previewed at (config `widths`). */
    widths?: unknown;
    /** What whoever opened the editor may do in it: change things, and write code. What the server lets them is the server's to say. */
    can?: { edit?: boolean; code?: boolean; build?: boolean };
    /** The site's own Vite dev server is running: it keeps the CSS up with the templates. */
    devServer?: boolean;
    /** Where the code editor's script is fetched from. */
    codeEditor?: string;
};

/** A small picture of the editor with one side of it filled in: which side a button of the top bar shows and hides. */
function ShownIcon({ part }: { part: 'left' | 'right' }) {
    return (
        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
            <rect x="1.5" y="2.5" width="13" height="11" rx="1.5" fill="none" stroke="currentColor" />
            {part === 'left' && <rect x="2.5" y="3.5" width="3.5" height="9" rx="0.5" fill="currentColor" />}
            {part === 'right' && <rect x="8.5" y="3.5" width="5" height="9" rx="0.5" fill="currentColor" />}
        </svg>
    );
}

/** A screen as wide as a device, roughly: a phone, a tablet or a desktop. */
function DeviceIcon({ width }: { width: number }) {
    return (
        <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
            {width < 600 ? (
                <rect x="4.5" y="1.5" width="7" height="13" rx="1.5" fill="none" stroke="currentColor" />
            ) : width < 1100 ? (
                <rect x="2.5" y="1.5" width="11" height="13" rx="1.5" fill="none" stroke="currentColor" />
            ) : (
                <>
                    <rect x="1.5" y="2.5" width="13" height="9" rx="1" fill="none" stroke="currentColor" />
                    <path d="M5.5 14.5h5M8 11.5v3" stroke="currentColor" />
                </>
            )}
        </svg>
    );
}

/** What a side folded away leaves at the edge: a narrow strip that opens it again. */
function Rail({ side, label, onOpen }: { side: 'left' | 'right'; label: string; onOpen(): void }) {
    return (
        <button type="button" className={`sve-rail sve-rail--${side}`} onClick={onOpen} aria-label={`Show the ${label.toLowerCase()}`} title={`Show the ${label.toLowerCase()}`}>
            <span className="sve-rail__mark" aria-hidden="true">
                {side === 'left' ? '»' : '«'}
            </span>
            <span className="sve-rail__label">{label}</span>
        </button>
    );
}

/** The page in the frame, as its bridge described it. `status` is what the server answered it with. */
type Page = { url: string; title: string; files: ManifestFile[]; status: number; /** Its CSS is Tailwind's browser build: a preview of what a build would make. */ preview: boolean; /** The entry it is of, when it is one. */ entry: ShownEntry | null };

/** What the side right of the page shows: the open template's code, what it can read, the page's content, or the site's custom CSS. */
type CodeTab = 'template' | 'css' | 'js';

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

const message = (error: unknown) => (error instanceof ApiError || error instanceof Error ? error.message : 'Something went wrong.');

/**
 * The editor's screen, in three parts:
 *
 * - left, the templates that drew the page and the tree of the open one:
 *   a click on a row selects it, outlines it on the page and scrolls the
 *   page to it;
 * - in the middle, the page itself, rendered for the editor (`?sve=1`):
 *   a click on it selects what was clicked, in the tree and in the code;
 * - right, the code: the open template, or the site's custom CSS. Saving
 *   (Ctrl+S) writes the file and draws the page again.
 *
 * Everything drawn over the page is drawn here, above the frame; the
 * page's bridge script says what the pointer is over and where things are.
 */
export function Editor({ startPath, widths: sentWidths, can, codeEditor, devServer }: Props) {
    const mayEdit = can?.edit !== false;
    const mayCode = mayEdit && can?.code !== false;
    /** The site has a build command, and whoever is here may run it. */
    const mayBuild = can?.build === true;
    const editorScript = typeof codeEditor === 'string' && codeEditor !== '' ? codeEditor : null;
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

    // The page the editor was asked to open on, as a path of this site and nothing else: the server
    // has checked it, and what a link is made from is checked here as well.
    const [firstPage] = useState(() => sitePath(startPath, window.location.origin) ?? '/');
    /** The address of the page in the frame, and what is being typed in its place, if anything. */
    const [address, setAddress] = useState(firstPage);
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
    /**
     * The template that is open as an answer arrives. A save, an undo or a change is answered a
     * moment later, and another template may have been opened meanwhile: the answer is then about
     * a template the editor no longer shows, and is not its to take.
     */
    const openNow = useRef(open);
    openNow.current = open;
    /** The code of each template as typed and not saved, by "origin:path": going to another template leaves it where it is. */
    const [drafts, setDrafts] = useState<Record<string, Draft>>({});
    /** The templates opened since the editor was, in the order they were: the tabs of the code. */
    const [tabs, setTabs] = useState<TemplateName[]>([]);
    const tabsNow = useRef(tabs);
    tabsNow.current = tabs;
    /** A place in a template's code the cursor is asked to go to. */
    const [codePlace, setCodePlace] = useState<CodePlace | null>(null);
    const asked = useRef(0);
    const draft = open === null ? null : (drafts[historyKey(open)] ?? null);
    /** What is typed in a template's code and not saved; null when it is the file again, or was saved. */
    const setDraft = (text: string | null, of: TemplateName | TemplateData | null = openNow.current) => {
        if (of === null) {
            return;
        }
        setDrafts((all) => {
            const { [historyKey(of)]: had, ...others } = all;

            if (text === null) {
                return had === undefined ? all : others;
            }
            if (!('source' in of) || of.source === null) {
                return all;
            }
            const next = typedDraft(had, { source: of.source, version: of.version }, text);

            return next === undefined ? others : { ...others, [historyKey(of)]: next };
        });
    };
    /** The open template has code that is not saved: the tree waits for it. */
    const unsaved = draft !== null;
    /** The custom CSS has text that is not saved. */
    const [cssUnsaved, setCssUnsaved] = useState(false);
    /** The custom script has text that is not saved. */
    const [jsUnsaved, setJsUnsaved] = useState(false);
    const anyUnsaved = Object.keys(drafts).length > 0 || cssUnsaved || jsUnsaved;
    // A template that is opened is a tab from then on.
    useEffect(() => {
        if (open !== null) {
            setTabs((all) => (all.some((tab) => sameTemplate(tab, open)) ? all : [...all, { path: open.path, origin: open.origin }]));
        }
    }, [open?.path, open?.origin]);
    const [template, setTemplate] = useState<Loaded<TemplateData>>({ state: 'idle' });
    /** Bumped to read the open template from disk again. */
    const [readings, setReadings] = useState(0);
    /** Which sides are shown and how wide the code is: as the editor was left (lib/layout.ts). */
    const [arranged, setArranged] = useState(readLayout);
    useEffect(() => writeLayout(arranged), [arranged]);
    const [codeTab, setCodeTab] = useState<CodeTab>('template');
    /** What the left side shows: the templates of the page, or the layers of the open one. Picking a template or an element brings its layers. */
    const [treeTab, setTreeTab] = useState<'templates' | 'layers'>('layers');
    /** The devices the page can be previewed at, and how it is shown now: as wide as the room, at one of them, or at all of them side by side. */
    const widths = useMemo(() => readWidths(sentWidths), [sentWidths]);
    const [view, setView] = useState<View>({ kind: 'full' });
    const room = useRef<HTMLDivElement>(null);
    const roomSize = useSize(room);
    /** The frames beside the one being edited, when the devices are side by side: the same page, to look at. */
    const previews = useRef<(HTMLIFrameElement | null)[]>([]);
    /** Every template there is, for the list of those not on this page. */
    const [all, setAll] = useState<TemplateInfo[] | null>(null);
    const [copying, setCopying] = useState(false);
    /** A change from the tree (a move, a delete) or an undo is on its way to the server. */
    const [applying, setApplying] = useState(false);
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

    /** The selected node of the open template, by its id in the tree. */
    const [selected, setSelected] = useState<string | null>(null);
    /** A node to select once the template it is in has been opened and read. */
    const toSelect = useRef<{ template: TemplateName; id: string; version: string } | null>(null);
    const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set());

    /** What the pointer is over: an element of the page, a row of the template list, a row of the tree. */
    const [hovered, setHovered] = useState<Mark | null>(null);
    const [pointedFile, setPointedFile] = useState<string | null>(null);
    const [pointedNode, setPointedNode] = useState<string | null>(null);
    const [measured, setMeasured] = useState<Measured | null>(null);

    const tree = template.state === 'ready' ? template.data.tree : null;
    const openFile = open === null ? null : (page?.files.find((file) => sameTemplate(file, open)) ?? null);
    // As they are when something that was waiting gets to look: the code's cursor is told of a moment late.
    const treeNow = useRef(tree);
    treeNow.current = tree;
    const selectedNow = useRef(selected);
    selectedNow.current = selected;
    const openFileNow = useRef(openFile);
    openFileNow.current = openFile;
    const busyNow = useRef(false);
    busyNow.current = applying;

    const tell = useBridge(frame, (said) => {
        if (said.type === 'ready') {
            const files = said.manifest.files;
            answered.current = true;
            redrawing.current = false;
            setWaiting(null);
            const status = said.manifest.status ?? 200;
            setPage({ url: said.url, title: said.title, files, status, preview: said.manifest.preview === true, entry: said.manifest.entry ?? null });
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
            if (file === undefined || !sameVersion(said.target.version, file.version)) {
                return;
            }
            if (sameTemplate(file, open) && template.state === 'ready' && !sameVersion(file.version, template.data.version)) {
                setSelected(null);
                say('problem', 'The preview is from an earlier file version. Refreshing it; select the element again.');
                setReadings((count) => count + 1);
                redraw();

                return;
            }
            // What was clicked is shown in its code, and in the layers.
            setCodeTab('template');
            setTreeTab('layers');
            if (sameTemplate(file, open)) {
                // An element of the open template: select it.
                if (findNode(tree, id) !== null) {
                    setSelected(id);
                    pointCode(file, findNode(tree, id), true);
                }
            } else {
                // An element of another template: open that one, and select the element once it is read.
                toSelect.current = { template: { path: file.path, origin: file.origin }, id, version: file.version as string };
                openTemplate({ path: file.path, origin: file.origin });
            }
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
     * Draws the page in the frame again, after a file changed. The editor
     * keeps what it shows until the new page says what it is, and so does
     * the frame: the page is drawn in the spare frame, scrolled to where
     * the old one is, and the two then change places.
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
        // ask the one that has loaded. A page drawn for the editor answers at once. The wait
        // for the page before this one is over: left running, it would come due while this
        // page is still being asked and say the page is gone.
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
        setCodeTab('template');
        setTreeTab('layers');
        if (sameTemplate(open, name)) {
            return;
        }
        if (toSelect.current !== null && !sameTemplate(toSelect.current.template, name)) {
            // An element clicked for a template that is no longer where the editor is going.
            toSelect.current = null;
        }
        // What was typed in the code of the template being left stays with its tab.
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

    // The code keeps showing the template it has while another is on its way: the editor stays as it
    // is, with what each tab was left with, and is not made anew for every template opened.
    const lastRead = useRef<TemplateData | null>(null);
    if (template.state === 'ready') {
        lastRead.current = template.data;
    }
    const readCode = template.state === 'ready' ? template.data : template.state === 'loading' ? lastRead.current : null;
    const codeDraft = readCode === null ? undefined : drafts[historyKey(readCode)];
    // A refreshed disk version must never become the base of an older draft.
    const codeOf = readCode === null || codeDraft === undefined ? readCode : { ...readCode, ...codeDraft.base };
    const draftConflict = readCode !== null && codeDraft !== undefined && codeDraft.base.version !== readCode.version;

    /** The editor's whole width, which the line between the page and the code is dragged across. */
    const shell = useRef<HTMLDivElement>(null);
    /** Dragging the line between the page and the code gives the one more room and the other less. */
    const divide = (event: ReactPointerEvent<HTMLDivElement>) => {
        const room = shell.current?.getBoundingClientRect();
        if (room === undefined || room.width === 0) {
            return;
        }
        event.preventDefault();
        const line = event.currentTarget;
        line.setPointerCapture(event.pointerId);
        // The page is in a frame, which would take the pointer for itself as it passes over.
        shell.current?.classList.add('sve-shell--dividing');
        const move = (moved: PointerEvent) => setArranged((now) => ({ ...now, share: shareOf((room.right - moved.clientX) / room.width) }));
        const done = () => {
            line.removeEventListener('pointermove', move);
            shell.current?.classList.remove('sve-shell--dividing');
        };
        line.addEventListener('pointermove', move);
        line.addEventListener('pointerup', done, { once: true });
        line.addEventListener('pointercancel', done, { once: true });
    };

    /** Closes a tab: its template is no longer among those the code keeps, and what was typed in it goes. */
    const closeTab = (name: TemplateName) => {
        if (tabs.length < 2) {
            return;
        }
        if (drafts[historyKey(name)] !== undefined && !window.confirm(`Close ${name.path} without saving what you changed in its code?`)) {
            return;
        }
        const at = tabs.findIndex((tab) => sameTemplate(tab, name));
        const left = tabs.filter((tab) => !sameTemplate(tab, name));
        setDraft(null, name);
        setTabs(left);
        if (sameTemplate(open, name)) {
            // The one beside it takes its place.
            openTemplate(left[Math.min(Math.max(at, 0), left.length - 1)]);
        }
    };

    /** Where the code's cursor is in the text as typed, in bytes, and in which template: what the code's completions are asked for. */
    const [codeCursor, setCodeCursor] = useState<{ of: string; byte: number } | null>(null);
    /** What can be read at the cursor, as last answered; while the next is asked it stays, most of it being the same. */
    const [codeFields, setCodeFields] = useState<FieldsAt | null>(null);

    /**
     * The code's cursor is somewhere else. A moment after it stops, the
     * node it is in is the selection: shown in the tree, and on the page.
     */
    const cursorWait = useRef<number | null>(null);
    const cursorAt = (byte: number, now: number) => {
        if (cursorWait.current !== null) {
            window.clearTimeout(cursorWait.current);
        }
        const of = lastRead.current === null ? null : historyKey(lastRead.current);
        cursorWait.current = window.setTimeout(() => {
            cursorWait.current = null;
            if (of !== null) {
                setCodeCursor((was) => (was !== null && was.of === of && was.byte === now ? was : { of, byte: now }));
            }
            const node = nodeAt(treeNow.current, byte);
            if (node === null || node.type === 'root' || node.id === selectedNow.current || busyNow.current) {
                return;
            }
            setSelected(node.id);
            const [first] = boxesOf(treeNow.current, node.id).ids;
            if (first !== undefined && openFileNow.current !== null) {
                tell({ type: 'reveal', mark: { file: openFileNow.current.key, id: first } });
            }
        }, 250);
    };

    // What can be read where the cursor is, in the text as it is typed: offered by the code editor
    // after `$entry->` and the like. Asked a moment after the typing or the cursor stops.
    const fieldsOf = codeOf !== null && codeOf.origin === 'site' && codeOf.editable && codeOf.source !== null ? codeOf : null;
    const fieldsText = fieldsOf === null ? null : (drafts[historyKey(fieldsOf)]?.text ?? fieldsOf.source);
    const fieldsByte = fieldsOf !== null && codeCursor !== null && codeCursor.of === historyKey(fieldsOf) ? codeCursor.byte : 0;
    const fieldsWanted = mayCode && template.state === 'ready' && fieldsOf !== null;
    useEffect(() => {
        if (!fieldsWanted || fieldsOf === null || fieldsText === null) {
            return;
        }
        let stale = false;
        const wait = window.setTimeout(() => {
            fieldsAt(fieldsOf.path, fieldsText, fieldsByte)
                .then((answer) => !stale && setCodeFields(answer))
                // Not answered this time: what was offered stays, the next move of the cursor asks again.
                .catch(() => undefined);
        }, 300);

        return () => {
            stale = true;
            window.clearTimeout(wait);
        };
    }, [fieldsWanted, fieldsOf?.path, fieldsText, fieldsByte]);

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

    /**
     * Sends the code's cursor to a node of a template: what a click on the
     * page, or on a row of the tree, does to the code. From the page the
     * keyboard goes there too; from the tree it stays in the tree.
     */
    const pointCode = (of: TemplateName, node: TreeNode | null, focus: boolean) => {
        if (node !== null && node.type !== 'root') {
            asked.current++;
            setCodePlace({ path: of.path, origin: of.origin, byte: node.start, asked: asked.current, focus });
        }
    };

    /** Selects a row of the tree, and scrolls the page to what it drew. */
    const select = (id: string) => {
        if (applying) {
            return;
        }
        setSelected(id);
        setCodeTab('template');
        if (open !== null) {
            pointCode(open, findNode(tree, id), false);
        }
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
                if (openFile !== null && !sameVersion(openFile.version, data.version)) {
                    setSelected(null);
                    redraw();
                }
                // The element that was clicked to get here. One clicked for another template is
                // not this reading's to take: the template that was open may still be arriving
                // when the click sends the editor on.
                const waitingFor = toSelect.current;
                if (waitingFor !== null && sameTemplate(waitingFor.template, open)) {
                    toSelect.current = null;
                    if (sameVersion(waitingFor.version, data.version) && findNode(data.tree, waitingFor.id) !== null) {
                        setSelected(waitingFor.id);
                        pointCode(open, findNode(data.tree, waitingFor.id), true);
                    }
                }
            })
            .catch((error: unknown) => !stale && setTemplate({ state: 'failed', error: message(error) }));

        return () => {
            stale = true;
        };
    }, [open?.path, open?.origin, readings, openFile?.version]);

    // Undo and redo from the keyboard, wherever in the editor it is, except in a field, which has
    // its own (the code has its own undo). Delete is not listened for here: only on a row, or on the page (the bridge).
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

    // Closing the tab with changes in the code: the browser asks first.
    useEffect(() => {
        if (!anyUnsaved) {
            return;
        }
        const ask = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', ask);

        return () => window.removeEventListener('beforeunload', ask);
    }, [anyUnsaved]);

    /** The code was saved: show the template as it is now, in the tree and on the page. */
    const saved = (data: TemplateData, sent: Draft) => {
        remember(data);
        say('done', `Saved ${data.path}.`);
        setDrafts((all) => {
            const key = historyKey(data);
            const { [key]: current, ...others } = all;
            if (!tabsNow.current.some((tab) => sameTemplate(tab, data))) {
                return others;
            }
            const next = savedDraft(current, sent, { source: data.source ?? sent.text, version: data.version });

            return next === undefined ? others : { ...others, [key]: next };
        });
        if (sameTemplate(openNow.current, data)) {
            setTemplate({ state: 'ready', data });
            // The ids of the old tree mean nothing in the new one.
            setSelected(null);
            setCollapsed(new Set());
        }
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
        redrawPreviews();
        // On a site that builds its CSS, what was built is now behind the templates.
        setBehind(true);
    };

    /** The devices beside the one being edited show the page again too. */
    const redrawPreviews = () =>
        previews.current.forEach((preview) => {
            try {
                preview?.contentWindow?.location.reload();
            } catch {
                // Not a page of this site any more: it is shown anew with the next page.
            }
        });

    /** A template was changed since the site's CSS was last built from here: the built CSS may lack what the change needs. */
    const [behind, setBehind] = useState(false);
    const [building, setBuilding] = useState(false);
    /** How the last build went, when it did not go well: shown until it is put away. */
    const [buildFailed, setBuildFailed] = useState<Built | null>(null);
    /** Runs the site's build command, and draws the page again with what it made. */
    const build = () => {
        if (building || !mayBuild) {
            return;
        }
        setBuilding(true);
        setBuildFailed(null);
        runBuild()
            .then((built) => {
                if (!built.ok) {
                    setBuildFailed(built);

                    return;
                }
                setBehind(false);
                say('done', `Built in ${built.seconds} seconds.`);
                redraw();
            })
            .catch((error: unknown) => say('problem', message(error)))
            .finally(() => setBuilding(false));
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
                if (sameTemplate(openNow.current, data)) {
                    setTemplate({ state: 'ready', data });
                    setNotice(null);
                    setSelected(rowFor(data.tree, step.selected));
                    setPointedNode(null);
                    setCollapsed(new Set());
                    revealing.current = true;
                }
                // Whether it opens as a tree may have changed with it.
                setAll(null);
                changedRedraw();
            })
            .catch((error: unknown) => {
                say('problem', message(error));
                if (error instanceof ApiError && error.reason === 'conflict') {
                    // The file has changed otherwise: read it again. Its history no longer applies.
                    if (sameTemplate(openNow.current, now)) {
                        setSelected(null);
                        setReadings((count) => count + 1);
                    }
                    redraw();
                }
            })
            .finally(() => setApplying(false));
    };

    /**
     * Sends a change made in the tree (a row dragged, duplicated or
     * deleted): the file is saved, the page drawn again, and the node the
     * change made or touched selected.
     */
    const apply = (operations: Operation[]) => {
        if (open === null || template.state !== 'ready' || applying) {
            return;
        }
        setApplying(true);
        const asked = open;

        applyOperations(open.path, template.data.version, operations)
            .then(({ template: changed, focus }) => {
                // The file was changed whichever template is open by now: its history has the step, and the page shows it.
                remember(changed);
                if (sameTemplate(openNow.current, changed)) {
                    setTemplate({ state: 'ready', data: changed });
                    setNotice(null);
                    // The ids of the old tree mean nothing in the new one.
                    setSelected(rowFor(changed.tree, focus));
                    setPointedNode(null);
                    setCollapsed(new Set());
                    revealing.current = true;
                }
                changedRedraw();
            })
            .catch((error: unknown) => {
                say('problem', message(error));
                if (error instanceof ApiError && error.reason === 'conflict') {
                    // The file is not what the editor shows any more: read it again.
                    if (sameTemplate(openNow.current, asked)) {
                        setSelected(null);
                        setReadings((count) => count + 1);
                    }
                    redraw();
                }
            })
            .finally(() => setApplying(false));
    };

    // --- What is shown on the page -------------------------------------------------

    /** The node under the pointer: a row of the tree, or an element of the open template on the page. */
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
            ? 'No template is open. Click a part of the page, or a template in the Templates tab.'
            : template.state === 'loading' || template.state === 'idle'
              ? 'Opening…'
              : template.state === 'failed'
                ? 'The template could not be opened.'
                : template.data.tree === null
                  ? 'This template can only be read as code, so it has no tree to show. Its code is on the right.'
                  : null;

    /** Why the open template can't be changed from the tree right now, when it can't. */
    const fixed =
        template.state !== 'ready'
            ? (layersNote ?? 'No template is open.')
            : !mayEdit
              ? 'You may look at this template here, not change it.'
              : !template.data.editable
                ? 'This is one of the CMS’s own views: it can’t be changed here.'
                : tree === null
                  ? 'This template can only be edited as code.'
                  : openFile !== null && !sameVersion(openFile.version, template.data.version)
                    ? 'The preview is from another file version. Wait for it to refresh before changing layers.'
                  : unsaved
                    ? 'The code of this template has changes that are not saved. Save or revert them first.'
                    : null;

    /** Moves, copies, unwraps or deletes a node, from a key in the tree or on the page. */
    const act = (id: string, action: Action) => {
        const operation = fixed === null ? actionOn(tree, id, action) : null;
        if (operation !== null) {
            apply([operation]);
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

    const isPackage = codeOf !== null && codeOf.origin === 'package';
    const plan = layout(view, widths, roomSize ?? { width: 0, height: 0 });
    const previewAddress = page === null ? undefined : (editorAddress(page.url, window.location.href) ?? undefined);

    return (
            <div
                ref={shell}
                className={`sve-shell sve-shell--simple${arranged.left ? '' : ' sve-shell--no-left'}${arranged.right ? '' : ' sve-shell--no-right'}`}
                style={{ '--sve-code-share': String(arranged.share) } as CSSProperties}
                aria-busy={applying}
            >
                <Head title={page === null ? 'Visual editor' : `${page.title} · Visual editor`} />

                <header className="sve-bar">
                    <Link
                        href={Sunrice.adminUrl()}
                        className="sve-bar__back"
                        title="Back to the admin"
                        // The admin is left without the page being left, so the browser does not ask: this does.
                        onBefore={() => !anyUnsaved || window.confirm('Leave the editor without saving what you changed in the code?')}
                    >
                        <span aria-hidden="true">←</span> Admin
                    </Link>
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

                    <div className="sve-modes" role="group" aria-label="What a click on the page does">
                        <button type="button" className="sve-modes__mode" aria-pressed={mode === 'select'} onClick={() => setMode('select')} title="A click on the page picks what was clicked, in the tree and in the code">
                            Select
                        </button>
                        <button type="button" className="sve-modes__mode" aria-pressed={mode === 'browse'} onClick={() => setMode('browse')} title="Links and forms on the page work as usual">
                            Browse
                        </button>
                    </div>

                    <div className="sve-history" role="group" aria-label="Undo and redo">
                        <button type="button" className="sve-button sve-button--quiet" disabled={!undoable} onClick={() => travel('undo')} title="Undo the last change to this template (Ctrl+Z)">
                            Undo
                        </button>
                        <button type="button" className="sve-button sve-button--quiet" disabled={!redoable} onClick={() => travel('redo')} title="Redo what was undone (Ctrl+Shift+Z)">
                            Redo
                        </button>
                    </div>

                    {mayBuild && (
                        <button
                            type="button"
                            className={`sve-button${behind && devServer !== true && !building ? '' : ' sve-button--quiet'} sve-bar__build`}
                            disabled={building}
                            onClick={build}
                            title={
                                devServer === true
                                    ? 'Run the site\'s build command. The dev server is running, so the page already has the CSS as it is now.'
                                    : behind
                                      ? 'A template was changed since the last build: the site\'s built CSS may lack what it needs. Run the site\'s build command.'
                                      : 'Run the site\'s build command'
                            }
                        >
                            {building ? 'Building…' : behind && devServer !== true ? 'Build needed' : 'Build'}
                        </button>
                    )}

                    {page?.preview === true && (
                        <span className="sve-tag sve-bar__preview" title="Tailwind's browser build is added to the page here, so a class shows before the CSS is built. Visitors get what the build makes: build to see the same on the site.">
                            Preview CSS
                        </span>
                    )}

                    {widths.length > 0 && (
                        <div className="sve-modes sve-devices" role="group" aria-label="Device to preview the page at">
                            <button type="button" className="sve-modes__mode" aria-pressed={view.kind === 'full'} onClick={() => setView({ kind: 'full' })} title="As wide as there is room">
                                Fit
                            </button>
                            {widths.map((each, at) => (
                                <button
                                    key={at}
                                    type="button"
                                    className="sve-modes__mode"
                                    aria-pressed={view.kind === 'one' && view.at === at}
                                    onClick={() => setView({ kind: 'one', at })}
                                    title={`${each.name}: ${each.width} pixels wide`}
                                >
                                    <DeviceIcon width={each.width} />
                                    <span className="sve-devices__name">{each.name}</span>
                                </button>
                            ))}
                            {widths.length > 1 && (
                                <button type="button" className="sve-modes__mode" aria-pressed={view.kind === 'all'} onClick={() => setView({ kind: 'all', at: view.kind === 'full' ? 0 : view.at })} title="Every device side by side">
                                    All
                                </button>
                            )}
                        </div>
                    )}

                    <div className="sve-shown" role="group" aria-label="What is shown beside the page">
                        <button
                            type="button"
                            className="sve-shown__part"
                            aria-pressed={arranged.left}
                            onClick={() => setArranged((now) => siding(now, 'left'))}
                            aria-label="Tree on the left"
                            title={arranged.left ? 'Hide the tree' : 'Show the tree'}
                        >
                            <ShownIcon part="left" />
                        </button>
                        <button
                            type="button"
                            className="sve-shown__part"
                            aria-pressed={arranged.right}
                            onClick={() => setArranged((now) => siding(now, 'right'))}
                            aria-label="Code on the right"
                            title={arranged.right ? 'Hide the code' : 'Show the code'}
                        >
                            <ShownIcon part="right" />
                        </button>
                    </div>

                    <ContentLink
                        entry={page?.entry ?? null}
                        onBack={() => {
                            // Whatever was saved there (or nothing): the page as it is now.
                            redraw();
                            redrawPreviews();
                        }}
                    />
                    <a className="sve-bar__link" href={page?.url ?? firstPage} target="_blank" rel="noreferrer">
                        View page ↗
                    </a>
                </header>

                {!arranged.left && <Rail side="left" label="Tree" onOpen={() => setArranged((now) => siding(now, 'left', true))} />}
                <Side side="left" shown={arranged.left}>
                    <div className="sve-code-tabs" role="tablist" aria-label="What the tree shows">
                        <button type="button" role="tab" className="sve-code-tabs__tab" aria-selected={treeTab === 'templates'} onClick={() => setTreeTab('templates')} title="The templates that drew this page, and the others">
                            Templates
                        </button>
                        <button
                            type="button"
                            role="tab"
                            className="sve-code-tabs__tab"
                            aria-selected={treeTab === 'layers'}
                            onClick={() => setTreeTab('layers')}
                            title={open === null ? 'The layers of the open template' : `The layers of ${shortName(open.path)}`}
                        >
                            Layers
                        </button>
                        <span className="sve-code-tabs__space" />
                        <button type="button" className="sve-side__fold" onClick={() => setArranged((now) => siding(now, 'left', false))} aria-label="Fold the tree to the side" title="Fold the tree to the side">
                            <span aria-hidden="true">«</span>
                        </button>
                    </div>
                    <Pane label="Templates" shown={treeTab === 'templates'}>
                        <TemplatesPanel files={page?.files ?? null} waiting={waiting} all={all} open={open} onOpen={openTemplate} onPoint={setPointedFile} />
                    </Pane>
                    <Pane label="Layers" shown={treeTab === 'layers'}>
                    <LayersPanel
                        tree={tree}
                        note={layersNote}
                        selected={selected}
                        lit={litNode}
                        collapsed={collapsed}
                        fixed={applying || fixed !== null}
                        onSelect={select}
                        onPoint={setPointedNode}
                        onMove={(id, drop) => apply([moveTo(id, drop)])}
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
                    </Pane>
                </Side>

                <main className={`sve-canvas sve-canvas--${mode}`}>
                    <div className={`sve-widths sve-widths--${view.kind}`} ref={room}>
                        {/* The frame being edited is always the first here, and the others keep their places: a frame that is
                            moved in the document loads its page anew. Where each is shown is said by `order`. */}
                        {plan.slots.map((slot, ordinal) => {
                            const sized = slot.at !== null;
                            const box = sized ? { width: slot.box, height: plan.boxHeight } : undefined;
                            const life = sized ? { width: slot.width, height: plan.height, transform: plan.scale === 1 ? undefined : `scale(${plan.scale})` } : undefined;

                            return (
                                <div key={slot.active ? 'edited' : `beside-${ordinal}`} className={`sve-slot${slot.active ? ' sve-slot--edited' : ''}`} style={sized ? { order: slot.at ?? 0, width: slot.box } : undefined}>
                                    {sized && (
                                        <header className="sve-slot__head">
                                            <span className="sve-slot__name">{slot.name}</span>
                                            <span className="sve-slot__width">{slot.width}</span>
                                            {!slot.active && (
                                                <button type="button" className="sve-slot__edit" onClick={() => setView({ kind: 'all', at: slot.at ?? 0 })} title={`Select on the page at ${slot.width} pixels`}>
                                                    Select here
                                                </button>
                                            )}
                                        </header>
                                    )}
                                    {slot.active ? (
                                        <div className="sve-stage" style={box}>
                                            {frames.map((one, which) => (
                                                <iframe
                                                    key={which}
                                                    ref={one}
                                                    className={which === front ? 'sve-frame' : 'sve-spare'}
                                                    style={life}
                                                    src={which === 0 ? firstAddress : undefined}
                                                    title={which === front ? 'Page being edited' : undefined}
                                                    aria-hidden={which === front ? undefined : true}
                                                    tabIndex={which === front ? undefined : -1}
                                                    onLoad={(event) => (event.currentTarget === frame.current ? loaded() : spareLoaded(event.currentTarget))}
                                                />
                                            ))}
                                            <Outlines outlines={holding ? held.current : outlines} scale={plan.scale} />
                                        </div>
                                    ) : (
                                        <div className="sve-stage sve-stage--beside" style={box}>
                                            <iframe
                                                ref={(element) => {
                                                    previews.current[ordinal - 1] = element;
                                                }}
                                                className="sve-beside"
                                                style={life}
                                                src={previewAddress}
                                                title={`The page at ${slot.width} pixels`}
                                                tabIndex={-1}
                                            />
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                        <NoticeBar notice={notice} onDismiss={() => setNotice(null)} />
                        {buildFailed !== null && (
                            <section className="sve-build" role="alert" aria-label="The build">
                                <header className="sve-build__head">
                                    <strong>{buildFailed.message}</strong>
                                    <span className="sve-build__took">{buildFailed.seconds} s</span>
                                    <button type="button" className="sve-notice__dismiss" onClick={() => setBuildFailed(null)} aria-label="Put this away" title="Put this away">
                                        ×
                                    </button>
                                </header>
                                {buildFailed.output !== '' && <pre className="sve-build__output">{buildFailed.output}</pre>}
                            </section>
                        )}
                    </div>
                </main>

                {!arranged.right && <Rail side="right" label="Code" onOpen={() => setArranged((now) => siding(now, 'right', true))} />}
                <aside className="sve-side sve-side--right sve-side--code" hidden={!arranged.right} aria-label="Code">
                    <div
                        className="sve-divider"
                        role="separator"
                        aria-orientation="vertical"
                        aria-label="How wide the code is"
                        aria-valuenow={Math.round(arranged.share * 100)}
                        aria-valuemin={20}
                        aria-valuemax={70}
                        tabIndex={0}
                        title="Drag to give the code more room, or less"
                        onPointerDown={divide}
                        onKeyDown={(event) => {
                            const step = event.key === 'ArrowLeft' ? 0.05 : event.key === 'ArrowRight' ? -0.05 : 0;
                            if (step !== 0) {
                                event.preventDefault();
                                setArranged((now) => ({ ...now, share: shareOf(now.share + step) }));
                            }
                        }}
                    />
                    <div className="sve-code-tabs" role="tablist" aria-label="What the side shows">
                        <button type="button" role="tab" className="sve-code-tabs__tab" aria-selected={codeTab === 'template'} onClick={() => setCodeTab('template')} title="The code of the open template. Ctrl+Space lists the fields and variables it can use at the cursor.">
                            Template
                            {Object.keys(drafts).length > 0 && <span className="sve-tab__dot" aria-label="not saved" />}
                        </button>
                        <button type="button" role="tab" className="sve-code-tabs__tab" aria-selected={codeTab === 'css'} onClick={() => setCodeTab('css')} title="CSS of your own, added to every page of the site">
                            Custom CSS
                            {cssUnsaved && <span className="sve-tab__dot" aria-label="not saved" />}
                        </button>
                        <button type="button" role="tab" className="sve-code-tabs__tab" aria-selected={codeTab === 'js'} onClick={() => setCodeTab('js')} title="JavaScript of your own, loaded on every page of the site">
                            Custom JS
                            {jsUnsaved && <span className="sve-tab__dot" aria-label="not saved" />}
                        </button>
                        <span className="sve-code-tabs__space" />
                        <button type="button" className="sve-side__fold sve-side__fold--right" onClick={() => setArranged((now) => siding(now, 'right', false))} aria-label="Fold the code to the side" title="Fold the code to the side">
                            <span aria-hidden="true">»</span>
                        </button>
                    </div>

                    {/* Both stay where they are while the other is shown: what is typed in either is kept. */}
                    <div className="sve-code-pane" hidden={codeTab !== 'template'}>
                        {codeOf === null ? (
                            <p className="sve-empty sve-code__none">{template.state === 'failed' ? template.error : template.state === 'loading' ? 'Loading…' : 'Click a part of the page, or a template on the left, to see its code.'}</p>
                        ) : (
                            <CodePanel
                                template={codeOf}
                                current={open}
                                mayCode={mayCode && template.state === 'ready'}
                                draft={drafts[historyKey(codeOf)]?.text ?? null}
                                conflict={draftConflict}
                                onDraft={(text) => setDraft(text, codeOf)}
                                tabs={tabs.map((tab) => ({ ...tab, unsaved: drafts[historyKey(tab)] !== undefined }))}
                                onOpenTab={openTemplate}
                                onCloseTab={closeTab}
                                goTo={codePlace}
                                onCursor={cursorAt}
                                fields={codeFields?.variables ?? null}
                                onSaved={saved}
                                onReload={() => {
                                    setDraft(null, codeOf);
                                    setSelected(null);
                                    setReadings((count) => count + 1);
                                }}
                                editorScript={editorScript}
                            >
                                {isPackage &&
                                    (codeOf.copyTo !== null ? (
                                        <div className="sve-code__banner">
                                            <p>
                                                This is one of the CMS’s own views, used while the site has no <code>{codeOf.copyTo}</code>. Copy it to the site to change it: the site uses the copy from then on.
                                            </p>
                                            {mayEdit && (
                                                <button type="button" className="sve-button" disabled={copying} onClick={() => copy(codeOf.path)}>
                                                    {copying ? 'Copying…' : 'Copy to site'}
                                                </button>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="sve-code__banner">
                                            This is one of the CMS’s own views, drawn by the CMS itself (a component, its draft banner or its comments). The CMS never looks for it among the site’s templates, so it can’t be changed here: style what it draws with Custom CSS, or change the template around it.
                                        </p>
                                    ))}
                                {!isPackage && !mayCode && <p className="sve-code__banner">You may read the code here, not change it.</p>}
                            </CodePanel>
                        )}
                    </div>
                    <div className="sve-code-pane" hidden={codeTab !== 'css'}>
                        <CustomFilePanel
                            kind="css"
                            mayEdit={mayEdit}
                            editorScript={editorScript}
                            onUnsaved={setCssUnsaved}
                            onSaved={(path) => {
                                say('done', `Saved ${path}.`);
                                redraw();
                                redrawPreviews();
                            }}
                        />
                    </div>
                    <div className="sve-code-pane" hidden={codeTab !== 'js'}>
                        {/* It runs in every visitor's browser: writing it is code. */}
                        <CustomFilePanel
                            kind="js"
                            mayEdit={mayCode}
                            editorScript={editorScript}
                            onUnsaved={setJsUnsaved}
                            onSaved={(path) => {
                                say('done', `Saved ${path}.`);
                                redraw();
                                redrawPreviews();
                            }}
                        />
                    </div>
                </aside>
            </div>
    );
}
