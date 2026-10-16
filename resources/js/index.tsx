import { Editor } from './Editor';
import { installLaunch } from './launch';

// The page for Inertia::render('VisualEditor/Editor') (EditorController).
// Full screen: no admin sidebar or header around it.
const page = Object.assign(Editor, { layout: null });

Sunrice.registerPage('VisualEditor/Editor', page);

// "Edit with Visual Editor" on every entry's page in the admin.
installLaunch();
