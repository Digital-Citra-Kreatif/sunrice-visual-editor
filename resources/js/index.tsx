import { Editor } from './Editor';

// The page for Inertia::render('VisualEditor/Editor') (EditorController).
// Full screen: no admin sidebar or header around it.
const page = Object.assign(Editor, { layout: null });

Sunrice.registerPage('VisualEditor/Editor', page);
