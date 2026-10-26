import { Editor } from './Editor';

/** The panel's runtime this script is built for (SDK_VERSION in @imajiner/admin-sdk). */
const RUNTIME = 3;

if (Sunrice.version !== RUNTIME) {
    throw new Error(`[visual editor] This script was built for the panel's runtime ${RUNTIME}, but the panel runs ${Sunrice.version}: update sunrice/visual-editor, or rebuild it (npm run build).`);
}

// The page for Inertia::render('VisualEditor/Editor') (EditorController).
// Full screen: no admin sidebar or header around it.
Sunrice.registerPage('VisualEditor/Editor', Object.assign(Editor, { bare: true }));
