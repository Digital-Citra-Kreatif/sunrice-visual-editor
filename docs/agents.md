# AI agents

The Sunrice panel has an MCP server (`/mcp`) that lets an AI agent (Claude
Code, Cursor, any MCP client) work on a site as the user its access token
belongs to: read its structure, write content, write templates. See the
panel's AI access docs (Help → Developer docs → Panel) for connecting one.

The CMS's `cms_write_template` tool writes a whole file, for super admins
where the site allows it. That is right for a new template. For a small
change to a template that exists it is a poor fit: the agent has to
reproduce every other line exactly, and now and then it will not.

This plugin adds two tools to the panel's server that work on a template
the way the editor's screen does: by naming a node and saying what to do
to it. They are there while the editor is on, for tokens of people who may
use it; a read-only token gets only the first.

## `visual_editor_get_template_tree`

Read-only.

- Without arguments: the site's templates and the CMS's own views, and
  whether each opens as a tree.
- With `path` (`"blocks/hero.blade.php"`): the template's `version` and its
  tree. `origin: "package"` reads one of the CMS's own views.

A node of the tree:

```json
{
    "id": "161",
    "type": "element",
    "kind": "visual",
    "name": "section",
    "attributes": [
        {"name": "class", "value": "block hero"},
        {"name": "id", "value": "block-{{ $block->id }}", "dynamic": true}
    ],
    "can": ["move", "delete", "wrap", "insert", "attributes"],
    "children": []
}
```

`can` lists what may be done to the node. A node that has to stay where it
is (an `@else`, a tag that does not nest) lists neither `move` nor
`delete`.

## `visual_editor_edit_template`

Takes `path`, the `version` that was read, and a list of `operations`:

```json
{
    "path": "blocks/hero.blade.php",
    "version": "530d06a59feb67321b62b83730af56a268df3938",
    "operations": [
        {"type": "addClass", "node": "161", "class": "hero--wide"},
        {"type": "setAttribute", "node": "359", "name": "loading", "value": "lazy"}
    ]
}
```

| Operation | Takes | Does |
| --- | --- | --- |
| `setText` | `text` | the text of a text node, written as text |
| `setAttribute` | `name`, `value`, `blade?` | sets an attribute; `blade: true` writes the value as Blade |
| `removeAttribute` | `name` | |
| `addClass`, `removeClass` | `class` | |
| `setId`, `setTag` | `id`, `tag` | |
| `setExpression` | `expression` | what an echo prints |
| `setEchoRaw` | `raw` | print as HTML (`{!! !!}`) or as text |
| `setArguments` | `arguments` | what is between the brackets of a directive |
| `insertBefore`, `insertAfter`, `append`, `prepend` | `blade` | Blade that stands on its own, beside the node or inside it |
| `wrap` | `wrapper` | puts the node inside `<div></div>` or a condition |
| `unwrap` | | takes the node away and keeps what is inside it |
| `move` | `target`, `position` | `before`, `after` or `inside` the target |
| `duplicate`, `remove` | | |
| `setCode` | `code` | rewrites the node as Blade written by hand |

Up to 200 operations in one call. They are applied together, all or none.

The answer has the new `version`, the new `tree`, and `focus`: the node the
last operation made or changed. Node ids are those of one version of the
file, so after a change the agent goes on from the tree in the answer.

## What is checked

The same as for a change made on the editor's screen
([how it works](how-it-works.md#before-anything-is-written)):

- The file must still be the version named. If someone saved it meanwhile,
  the change is refused and the answer gives the version it now has.
- The result must open as a tree and compile.
- A refusal says why and which operation it was, and nothing is written.
- The previous version is kept as a backup, and the change is in the
  activity log under the token's user, marked as made by an agent.

## Who may

The tools act as the user the MCP token belongs to, and are held to what
that user may do in the editor ([Permissions](permissions.md)):

| Tool | Needs |
| --- | --- |
| `visual_editor_get_template_tree` | `visual-editor.open` |
| `visual_editor_edit_template` | `visual-editor.edit` |
| `visual_editor_edit_template` writing Blade or PHP of the agent's own | `visual-editor.code` |

And, as every tool on the panel's server, the panel's own permission to
connect agents (`sunrice.ai.connect`).

An agent whose user has `edit` and not `code` can restructure markup, set
texts and classes, and insert what the editor's own lists offer. It can't
make the template run new PHP, by the same check as for a person.

Two switches turn changes off: the editor being
[off](installation.md#where-it-is-on) (the tools exist only where it is
on, which is local by default), and the editor's `agents` setting set to
`false` (`SUNRICE_VISUAL_EDITOR_AGENTS`), which keeps agents from changing
templates through it, reading aside.

## Stylesheets

There is no tool yet that changes one declaration of a stylesheet, and the
CMS's template tools write only Blade files: the theme's CSS is changed in
the editor's screen, or in the project's files.
