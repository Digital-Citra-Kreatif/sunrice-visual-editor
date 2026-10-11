# AI agents

Sunrice has an MCP server that lets an AI agent (Claude Code, Cursor, any
MCP client) work on a site: read its structure, write content, write
templates. See Sunrice's own
[AI agents](https://github.com/littleboy130491/sunrice-cms/blob/main/docs/ai-agents.md)
page for connecting one.

Sunrice's `write_template` tool writes a whole file. That is right for a
new template. For a small change to a template that exists it is a poor
fit: the agent has to reproduce every other line exactly, and now and then
it will not.

This package adds two tools that work on a template the way the editor's
screen does: by naming a node and saying what to do to it.

> **Not on a released Sunrice yet.** The tools are listed to agents once
> Sunrice lets packages add tools to its server
> ([sunrice-cms#40](https://github.com/littleboy130491/sunrice-cms/pull/40)).
> On a Sunrice without that, nothing is registered and nothing fails.

## `get_template_tree`

Read-only.

- Without arguments: the site's templates and Sunrice's own views, and
  whether each opens as a tree.
- With `path` (`"blocks/hero.blade.php"`): the template's `version` and its
  tree. `origin: "package"` reads one of Sunrice's own views.

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

## `edit_template`

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
  activity log under the token's user, marked as made by AI.

## Who may

The tools act as the user the MCP token belongs to, and are held to what
that user may do in the editor ([Permissions](permissions.md)):

| Tool | Needs |
| --- | --- |
| `get_template_tree` | `visual-editor.open` |
| `edit_template` | `visual-editor.edit` |
| `edit_template` writing Blade or PHP of the agent's own | `visual-editor.code` |

An agent whose user has `edit` and not `code` can restructure markup, set
texts and classes, and insert what the editor's own lists offer. It can't
make the template run new PHP, by the same check as for a person.

Two switches turn the tools off entirely: the editor being
[off](installation.md#where-it-is-on) (they exist only where it is on,
which is local by default), and Sunrice's `sunrice.mcp.templates` set to
`false`, which keeps agents from templates altogether.

## Stylesheets

There is no tool yet that changes one declaration of a stylesheet. An agent
changes the theme's CSS with Sunrice's `write_template`, which writes the
whole file.
