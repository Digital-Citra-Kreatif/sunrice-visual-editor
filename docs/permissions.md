# Permissions

The editor has three permissions of its own. They are given to roles in
Sunrice's admin (**Users → Roles**, under *Visual editor*), and exist after
`php artisan sunrice:sync-permissions` has run.

| Permission | Name in the admin | Lets someone |
| --- | --- | --- |
| `visual-editor.open` | Open the visual editor | look: the page, its templates, their layers, their styles |
| `visual-editor.edit` | Change templates and styles in the visual editor | change templates and stylesheets without writing code |
| `visual-editor.code` | Edit template code in the visual editor | write Blade and PHP |

Each includes the ones above it, so a role needs only the highest it should
have. Super Admins have all three. The user also needs to get into the
admin at all (`sunrice.access-admin`). While the editor is
[off](installation.md#where-it-is-on), none of them lets anyone do
anything.

## Why code is separate

A Blade template is PHP. Someone who can write `@php` in a template can
read the site's `.env`, its database and its files. That is the same power
as deploying code, and most people who should be able to move a heading or
change a colour should not have it.

So the promise for someone with `edit` and without `code` is:

> A template runs no PHP after their change that it did not run before,
> except code the editor writes itself.

## What `edit` can do

- Add, move, copy, wrap and delete elements; change tags, ids, classes,
  attributes and text.
- Insert what the Insert list offers, apart from its PHP item.
- Print a field chosen from the Fields list, build a condition or a loop
  from choices, set up `<x-sunrice::entries>` from its lists.
- Move, copy and delete code that is already in the template.
- Everything in the Style and CSS tabs.
- Undo and redo.

## What `edit` cannot do

- Write in the code panel. It opens as **View the code** and takes no
  typing.
- Rewrite a node as code.
- Type what an echo prints, a condition, a loop's source, an include, or an
  attribute written in Blade. Those controls are shown and not changed; the
  lists beside them are still there to choose from.
- Insert the "PHP" item, or paste Blade that would run something.

## How it is held

Not by hiding buttons. The same check runs on the server for every way of
changing a template: the editor's screen, its API called directly, and the
[tools for AI agents](agents.md). It has two parts, and the second does not
depend on the first.

1. **What may be new.** Each operation is looked at for the code it brings.
   That code counts only if the editor offers exactly it, at that place: a
   field from the picker, an item of the Insert list, a condition or a loop
   built from choices, a plain value for one of Sunrice's components.
   Anything typed counts for nothing.
2. **What is new.** The template before the change and after it are both
   compiled by Laravel's own Blade compiler, and every piece of PHP in the
   result is compared. Each piece has to be one that was there before, or
   one that the code counted in (1) compiles to. Anything else, and the
   change is refused and nothing is written.

The second part looks at what Blade makes of the file, not at what was
asked for. A request shaped in some way the first part has no rule for
still has to get its PHP past the comparison.

## What you should know before giving someone `edit`

This is a real line and it is tested, but be clear about what it is.

- **`edit` can add any HTML, and that includes `<script>`.** It is for
  people you would let change the site's markup. It protects the server,
  not the site's visitors, from that person.
- **`edit` can rearrange code that is there.** Moving a `@php` block above
  the condition that guarded it, or copying a loop, runs nothing new but
  can change what a page does.
- **Undo for someone without `code` reaches back twelve hours.** An undo
  writes a whole earlier version of the file. For them, that version has to
  be one the editor's backups hold from the last twelve hours, so an "undo"
  can't be used to write a file of their own. Leave `backups` on.
- **The check has had one reviewer.** It is built to fail closed, and
  `tests/Feature/CodePermissionTest.php` holds 226 cases, including the
  ways found to get around earlier versions of it. It has not had an
  independent security review. Until it has, treat `edit` as something for
  people you trust not to attack the site, on a copy of the site that is
  not production.

If you find a way through it, please report it privately rather than in a
public issue.
