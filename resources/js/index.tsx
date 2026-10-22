import { Editor } from './Editor';
import { definePages } from './lib/panel.ts';

// The page for Inertia::render('VisualEditor/Editor') (EditorController).
// Full screen: no panel sidebar or header around it.
definePages({ 'VisualEditor/Editor': Object.assign(Editor, { bare: true }) });
