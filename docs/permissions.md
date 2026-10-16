# Permissions

The editor has four permissions of its own: three for working on
templates, each including the one before it, and one apart from them for
running the site's build. They are given to roles in Sunrice's admin
(**Users → Roles**, under *Visual editor*), and exist after
`php artisan sunrice:sync-permissions` has run.

| Permission | Name in the admin | Lets someone |
| --- | --- | --- |
| `visual-editor.open` | Open the visual editor | look: the page, its templates, their layers, their code, the fields |
| `visual-editor.edit` | Change templates and styles in the visual editor | change templates without writing code, and write the custom CSS |
| `visual-editor.code` | Edit template code in the visual editor | write Blade and PHP |

Each includes the ones above it, so a role needs only the highest it should
have.

| Permission | Name in the admin | Lets someone |
| --- | --- | --- |
| `visual-editor.build` | Run the site's build command from the visual editor | press **Build**: run the one command the site's configuration names |

None of the three includes this one, not even `code`, and it includes none
of them: someone needs one of the three to be in the editor at all, and
this one to build. [Building](#building) says why.

Super Admins have all four. The user also needs to get into the
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

On the editor's screen:

- Move, duplicate and delete in the tree, by dragging a row or by key.
  That includes code that is already in the template.
- Undo and redo.
- Write and save the [custom CSS](styles.md#custom-css). CSS runs no PHP.
- **Copy to site**: get a copy of one of Sunrice's fallback views that the
  site uses.
- Read the code of every template.

Through the editor's API and the [tools for AI agents](agents.md), which
take more operations than the screen sends, also: change tags, ids,
classes, attributes and text, and insert what the editor's own lists
offer, such as a field from the picker or a condition built from choices.

## What `edit` cannot do

- Write in the **Template** tab. The code opens read-only and takes no
  typing.
- Write a field from the **Fields** tab: that writes Blade at the cursor.
- Through the API: rewrite a node as code, type what an echo prints, a
  condition, a loop's source, an include or an attribute written in
  Blade, or insert Blade that would run something.

What a person's [content](how-it-works.md#the-entrys-content) can be
changed to is Sunrice's to say, by its own permissions: the **Content** tab
is Sunrice's admin page.

## How it is held

Not by hiding buttons. The same check runs on the server for every way of
changing a template: the editor's screen, its API called directly, and the
[tools for AI agents](agents.md). It has two parts, and the second does not
depend on the first.

1. **What may be new.** Each operation is looked at for the code it brings.
   That code counts only if the editor offers exactly it, at that place: a
   field from the picker, an item the editor offers to insert, a condition
   or a loop built from choices, a plain value for one of Sunrice's
   components.
   Anything typed counts for nothing.
2. **What is new.** The template before the change and after it are both
   compiled by Laravel's own Blade compiler, and every piece of PHP in the
   result is compared. Each piece has to be one that was there before, or
   one that the code counted in (1) compiles to. Anything else, and the
   change is refused and nothing is written.

The second part looks at what Blade makes of the file, not at what was
asked for. A request shaped in some way the first part has no rule for
still has to get its PHP past the comparison.

## Building

On a site that builds its CSS, a class added to a template has no style
for visitors until the build has run again. With a
[build command](installation.md#settings) set, the editor's top bar has a
**Build** button for people with `visual-editor.build`.

It is a permission of its own because it starts a program on the server,
which nothing else in the editor does:

- **What runs is the command in the site's configuration**
  (`build.command`), exactly. Nothing a request sends is part of it:
  someone with the permission chooses when to build, never what is run.
  Whoever can change the site's configuration or its `package.json`
  decides that, and they can run code on the server already.
- It runs as the web server's user, in the site's directory, one build at
  a time, and is stopped after `build.timeout` seconds.
- Every run is in the activity log, with who ran it and how it ended.
- With no command set, the permission does nothing and there is no button.

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
