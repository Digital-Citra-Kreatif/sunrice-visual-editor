<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Blade\Variables;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Sunrice\VisualEditor\Templates\TemplateRepository;

/**
 * What a template can read at one place in it, and what each of those is.
 *
 * It comes from three sides. From the file: an entry's template has the
 * `$entry` and `$values` of the collections it draws, a block's has its `$block`, and a
 * partial has what the templates that include it pass on. From the place:
 * the item of every loop around it, what the template has set above it,
 * what a component gives to what is inside its tag. And from the site:
 * the page being shown, global sets and menus, which are everywhere.
 *
 * It reads the templates and never draws them: a variable it can't follow
 * is listed as a plain value, never as a guess.
 */
final class Scope
{
    /** What a variable is taken to be by its name alone, when nothing passes it on: the CMS's own names for them. */
    private const BY_NAME = ['entry' => 'entry', 'entries' => 'entries', 'term' => 'term_page', 'terms' => 'terms', 'block' => 'block', 'collection' => 'collection', 'taxonomy' => 'taxonomy', 'item' => 'entry', 'blocks' => 'blocks', 'items' => 'menu'];

    private const INCLUDES = ['include' => 0, 'includeif' => 0, 'includefirst' => 0, 'includewhen' => 1, 'includeunless' => 1, 'each' => 0];

    /** @var array<string, array<string, Variable>> what each template is given, by its view name */
    private array $given = [];

    /** @var array<string, true> templates whose givens are being worked out: one that includes itself is not asked twice */
    private array $resolving = [];

    /** @var array<string, Template|null> */
    private array $opened = [];

    public function __construct(private readonly Catalog $catalog, private readonly TemplateRepository $templates) {}

    /**
     * @param  Node|null  $node  the place; null for the template as a whole
     * @param  bool  $inside  for something going inside the node, not in its place: inside a loop its item is there too
     * @return list<Variable> what the place brings first, the innermost first; then what the file is given; then what every template has
     */
    public function at(TemplateFile $file, Template $template, ?Node $node, bool $inside = false): array
    {
        $given = $this->given($file, $template->source);
        $here = $this->here($node, $inside, $given);

        return array_values([...array_reverse($here), ...array_diff_key($given, $here), ...$this->everywhere()]);
    }

    /**
     * What a template has wherever in it one is, said without its tree: for
     * a text that does not open as one, while it is being typed.
     *
     * @return list<Variable> what the file is given, then what every template has
     */
    public function anywhere(TemplateFile $file, string $source): array
    {
        return array_values([...$this->given($file, $source), ...$this->everywhere()]);
    }

    /**
     * What the place itself brings: loops, what is set above, components.
     *
     * @param  array<string, Variable>  $given
     * @return array<string, Variable> by name, in the order they come to be
     */
    private function here(?Node $node, bool $inside, array $given): array
    {
        $path = [];
        for ($step = $node; $step !== null && $step->parent !== null; $step = $step->parent) {
            array_unshift($path, $step);
        }
        if ($inside && $node?->type === 'block' && count($node->children) === 1) {
            // A loop or a condition with one arm: inside it is inside that arm.
            $path[] = $node->children[0];
        }

        $here = [];
        // What is known so far: what the place has brought takes the place of what the file was given.
        $shapes = function () use ($given, &$here): array {
            return self::shapes([...$given, ...$here]);
        };
        foreach ($path as $index => $step) {
            // What the template sets before this, among what is beside it.
            foreach ($step->parent->children ?? [] as $beside) {
                if ($beside === $step) {
                    break;
                }
                foreach ($this->setBy($beside, $shapes()) as $name => [$shape, $written]) {
                    unset($here[$name]);
                    $here[$name] = new Variable('$'.$name, $shape ?? Shape::of(Shape::VALUE), Variable::SET, 'Set above: '.$written, sure: false);
                }
            }
            if ($index < count($path) - 1 || $inside) {
                foreach ($this->broughtBy($step, $shapes()) as $name => $variable) {
                    unset($here[$name]);
                    $here[$name] = $variable;
                }
            }
        }
        if ($inside) {
            // What goes inside goes after everything that is there: what that sets is above it too.
            $holder = $path === [] ? $node : $path[count($path) - 1];
            foreach ($holder->children ?? [] as $within) {
                foreach ($this->setBy($within, $shapes()) as $name => [$shape, $written]) {
                    unset($here[$name]);
                    $here[$name] = new Variable('$'.$name, $shape ?? Shape::of(Shape::VALUE), Variable::SET, 'Set above: '.$written, sure: false);
                }
            }
        }

        return $here;
    }

    /**
     * What a node sets when it is passed: `@php($image = …)`, a `@php … @endphp`.
     *
     * @param  array<string, Shape|null>  $shapes
     * @return array<string, array{0: Shape|null, 1: string}> by name: what it is, and how the template writes it
     */
    private function setBy(Node $node, array $shapes): array
    {
        if ($node->type !== 'atom') {
            return [];
        }
        $code = match (true) {
            $node->kind === 'directive' && strtolower($node->name) === 'php' && $node->args !== null => $node->args,
            $node->kind === 'php' => (string) preg_replace(['/^\s*(@php|<\?php|<\?=?)/', '/(@endphp|\?>)\s*$/'], '', $node->open),
            default => null,
        };
        if ($code === null) {
            return [];
        }

        $set = [];
        foreach (Expression::sets($code, $shapes, $this->catalog) as $name => $shape) {
            $set[$name] = [$shape, self::brief($node->kind === 'php' ? '$'.$name.' = …' : $code)];
        }

        return $set;
    }

    /**
     * What a loop, a condition or a component's tag gives to what is inside it.
     *
     * @param  array<string, Shape|null>  $shapes
     * @return array<string, Variable>
     */
    private function broughtBy(Node $node, array $shapes): array
    {
        if ($node->type !== 'branch') {
            return [];
        }
        $name = strtolower($node->name);
        $brought = [];

        if ($node->kind === 'component') {
            $attribute = fn (string $attribute): array => preg_match('/(?<![:\w-])'.$attribute.'="([^"{}$]+)"/', $node->open, $match) === 1 ? [$match[1]] : [];
            $gives = match ($name) {
                'sunrice-cms::entries', 'sunrice-cms::entry-filter' => new Field('entries', 'Entries', 'list', Shape::listOf(Shape::entry($attribute('collection'))), '%s->entries', builtIn: true),
                'sunrice-cms::terms' => new Field('terms', 'Terms', 'list', Shape::listOf(Shape::term($attribute('taxonomy'))), '%s->terms', builtIn: true),
                default => null,
            };
            if ($gives !== null) {
                $brought['component'] = new Variable('$component', Shape::group([$gives]), Variable::COMPONENT, 'What <x-'.$node->name.'> found');
            }
        } elseif ($node->kind === 'directive' && in_array($name, ['foreach', 'forelse'], true)) {
            $loop = Expression::loop((string) $node->args, $shapes, $this->catalog);
            if ($loop !== null) {
                $over = self::brief((string) preg_replace('/\s+as\s+[^)]*$/s', '', (string) $node->args));
                if ($loop['key'] !== null) {
                    $brought[$loop['key']] = new Variable('$'.$loop['key'], Shape::of(Shape::VALUE), Variable::LOOP, 'The key of each of '.$over);
                }
                $brought[$loop['item']] = new Variable('$'.$loop['item'], $loop['shape'] ?? Shape::of(Shape::VALUE), Variable::LOOP, 'Each of '.$over);
            }
        } elseif ($node->kind === 'directive' && in_array($name, ['if', 'elseif'], true)) {
            // @if ($cta = $values['cta']): inside, it is there.
            foreach (Expression::read((string) $node->args, $shapes, $this->catalog)[1] as $set => $shape) {
                $brought[$set] = new Variable('$'.$set, $shape ?? Shape::of(Shape::VALUE), Variable::SET, 'Set by the condition: '.self::brief((string) $node->args));
            }
        }

        return $brought;
    }

    /**
     * What a template has before any of it is read: from the page it
     * draws, or from the templates that include it.
     *
     * @return array<string, Variable> by name
     */
    private function given(TemplateFile $file, string $source): array
    {
        if (isset($this->given[$file->view])) {
            return $this->given[$file->view];
        }

        $of = fn (string $role): array => array_values(array_unique(array_column(array_filter($file->usedBy, fn (array $use) => $use['role'] === $role), 'handle')));
        $draws = fn (string $role): bool => $file->role === $role || $of($role) !== [];
        $collection = fn (array $handles, bool $sure = true): Variable => new Variable('$collection', Shape::collection(count($handles) === 1 ? $handles[0] : null), Variable::PAGE, 'The collection of the page', $sure);

        $given = [];
        $text = fn (string $name, string $what): Variable => new Variable('$'.$name, Shape::of(Shape::TEXT), Variable::PAGE, $what);
        $yes = fn (string $name, string $what): Variable => new Variable('$'.$name, Shape::of(Shape::BOOL), Variable::PAGE, $what);
        if ($draws('entry')) {
            $given['entry'] = new Variable('$entry', Shape::entry($of('entry')), Variable::PAGE, 'The entry the page shows');
            $given['title'] = $text('title', 'The title of the entry (its draft\'s in a preview)');
            $given['values'] = new Variable('$values', Shape::values('collection', $of('entry')), Variable::PAGE, 'The fields of the entry');
            $given['collection'] = $collection($of('entry'));
            $given['preview'] = $yes('preview', 'Whether this is a preview of the draft');
            $given['fallback'] = $yes('fallback', 'Whether the page shows the main language\'s content, untranslated');
        }
        if ($draws('listing')) {
            $given['entries'] = new Variable('$entries', Shape::listOf(Shape::entry($of('listing'))), Variable::PAGE, 'The entries listed on the page');
            $given['title'] ??= $text('title', 'The title of the listing page');
            $given['values'] ??= new Variable('$values', Shape::values('listing', $of('listing')), Variable::PAGE, 'The fields of the listing page');
            $given['collection'] = $collection([...$of('entry'), ...$of('listing')]);
        }
        if ($draws('term')) {
            $given['term'] = new Variable('$term', Shape::termPage($of('term')), Variable::PAGE, 'The term the page shows');
            $given['taxonomy'] = new Variable('$taxonomy', Shape::of(Shape::TAXONOMY), Variable::PAGE, 'The taxonomy of the term');
            $given['title'] ??= $text('title', 'The title of the term');
            $given['values'] ??= new Variable('$values', Shape::values('taxonomy', $of('term')), Variable::PAGE, 'The fields of the term');
            $given['entries'] ??= new Variable('$entries', Shape::listOf(Shape::entry()), Variable::PAGE, 'The entries listed on the page');
            $given['fallback'] ??= $yes('fallback', 'Whether the page shows the main language\'s content, untranslated');
        }
        if (isset($given['values'])) {
            $given['richText'] = new Variable('$richText', Shape::listOf(Shape::of(Shape::TEXT)), Variable::PAGE, 'The handles of the rich text fields, the only HTML to print as it is');
        }
        if ($file->role === 'search') {
            $given['query'] = new Variable('$query', Shape::of(Shape::TEXT), Variable::PAGE, 'What was searched for');
            $given['results'] = new Variable('$results', Shape::listOf(Shape::entry()), Variable::PAGE, 'The entries found');
            $given['title'] ??= $text('title', 'The title of the search page');
        }
        if ($file->role === 'block') {
            $fieldset = basename($file->path, '.blade.php');
            $known = isset($this->catalog->titles('fieldset')[$fieldset]);
            $given['block'] = new Variable('$block', Shape::block($known ? [$fieldset] : []), Variable::PAGE, $known ? 'The '.$this->catalog->titles('fieldset')[$fieldset].' block being drawn' : 'The block being drawn');
        }

        // What it reads and nothing above gives: from the templates that include it.
        $needed = array_values(array_diff(Variables::needed($source), array_keys($given)));
        $topLevel = $this->resolving === [];
        $passed = $needed === [] ? [] : $this->passed($file);
        foreach ($needed as $name) {
            $shape = $passed[$name] ?? match (self::BY_NAME[$name] ?? null) {
                'entry' => Shape::entry(),
                'entries' => Shape::listOf(Shape::entry()),
                'term_page' => Shape::termPage(),
                'terms' => Shape::listOf(Shape::term()),
                'block' => Shape::block(),
                'collection' => Shape::collection(),
                'blocks' => Shape::listOf(Shape::block()),
                'menu' => Shape::listOf(Shape::of(Shape::MENU_ITEM)),
                'taxonomy' => Shape::of(Shape::TAXONOMY),
                default => Shape::of(Shape::VALUE),
            };
            $given[$name] = new Variable('$'.$name, $shape, Variable::INCLUDED, isset($passed[$name]) ? 'Passed on by the templates that include this one' : 'Has to be given by whatever includes this template');
        }

        // Worked out while another template's was: it may lack what that one would have passed.
        return $topLevel ? $this->given[$file->view] = $given : $given;
    }

    /**
     * What the site's templates hand to one they include: what they name
     * in the `@include`, and everything else they have at that place,
     * which Blade passes on too.
     *
     * @return array<string, Shape>
     */
    private function passed(TemplateFile $file): array
    {
        if (isset($this->resolving[$file->view])) {
            return [];
        }
        $this->resolving[$file->view] = true;
        $named = [];
        $inherited = [];

        try {
            foreach ($this->templates->files() as $other) {
                $template = $other->origin === TemplateFile::SITE ? $this->open($other) : null;
                if ($template === null || ! str_contains($template->source, $file->view)) {
                    continue;
                }
                foreach ($template->root->walk() as $node) {
                    $directive = $node->type === 'atom' && $node->kind === 'directive' ? strtolower($node->name) : '';
                    if (! isset(self::INCLUDES[$directive]) || $node->args === null) {
                        continue;
                    }
                    $arguments = array_slice(Expression::arguments($node->args), self::INCLUDES[$directive]);
                    $names = $directive === 'includefirst' ? $this->literals($arguments[0] ?? '') : [Expression::literal($arguments[0] ?? '')];
                    if (! in_array($file->view, $names, true)) {
                        continue;
                    }

                    $there = self::shapes($this->at($other, $template, $node));
                    if ($directive === 'each') {
                        // @each('view', $items, 'item')
                        $item = Expression::literal($arguments[2] ?? '');
                        $list = Expression::read($arguments[1] ?? '', $there, $this->catalog)[0];
                        if ($item !== null) {
                            $named[$item] = self::merged($named[$item] ?? null, $list?->isList() ? $list->item : null);
                        }

                        continue;
                    }
                    $data = Expression::pairs($arguments[1] ?? '');
                    foreach ($data as $name => $code) {
                        $named[$name] = self::merged($named[$name] ?? null, Expression::read($code, $there, $this->catalog)[0]);
                    }
                    foreach (array_diff_key($there, $data) as $name => $shape) {
                        $inherited[$name] = self::merged($inherited[$name] ?? null, $shape);
                    }
                }
            }
        } finally {
            unset($this->resolving[$file->view]);
        }

        return array_filter([...$inherited, ...array_filter($named)]);
    }

    /**
     * What every template of the site can read.
     *
     * @return list<Variable>
     */
    private function everywhere(): array
    {
        $everywhere = [
            new Variable('cms_page()', Shape::of(Shape::PAGE), Variable::SITE, 'The page being shown', sure: false),
            new Variable('$locale', Shape::of(Shape::TEXT), Variable::SITE, 'The language of the page'),
        ];
        foreach (SunriceBridge::globals() as $set) {
            $everywhere[] = new Variable("cms_global('".addcslashes($set['handle'], "'\\")."')", Shape::globals($set['handle']), Variable::SITE, $set['title']);
        }
        foreach (SunriceBridge::menus() as $menu) {
            $everywhere[] = new Variable("cms_menu('".addcslashes($menu['handle'], "'\\")."')", Shape::listOf(Shape::of(Shape::MENU_ITEM)), Variable::SITE, $menu['title']);
        }

        return $everywhere;
    }

    private function open(TemplateFile $file): ?Template
    {
        if (! array_key_exists($file->file, $this->opened)) {
            try {
                $this->opened[$file->file] = Template::open((string) file_get_contents($file->file));
            } catch (Unsupported) {
                $this->opened[$file->file] = null;
            }
        }

        return $this->opened[$file->file];
    }

    /**
     * The plain texts in an array written out: the views of an @includeFirst.
     *
     * @return list<string|null>
     */
    private function literals(string $array): array
    {
        $array = trim($array);

        return str_starts_with($array, '[') && str_ends_with($array, ']') ? array_map(Expression::literal(...), Expression::arguments(substr($array, 1, -1))) : [];
    }

    /**
     * The variables among them, by name, with what each is.
     *
     * @param  iterable<Variable>  $variables
     * @return array<string, Shape>
     */
    private static function shapes(iterable $variables): array
    {
        $shapes = [];
        foreach ($variables as $variable) {
            $name = $variable->name();
            if ($name !== null && ! isset($shapes[$name])) {
                $shapes[$name] = $variable->shape;
            }
        }

        return $shapes;
    }

    /** What something is when it is one thing in one place and another elsewhere: an entry of either collection. */
    private static function merged(?Shape $a, ?Shape $b): ?Shape
    {
        if ($a === null || $b === null || $a->kind === Shape::VALUE) {
            return $b !== null && $b->kind !== Shape::VALUE ? $b : ($a ?? $b);
        }
        if ($a->isList() && $b->isList()) {
            $item = self::merged($a->item, $b->item);

            return $item === null ? $a : Shape::listOf($item);
        }
        if ($a->kind !== $b->kind || ! in_array($a->kind, [Shape::ENTRY, Shape::TERM, Shape::TERM_PAGE, Shape::BLOCK, Shape::VALUES], true)) {
            return $a;
        }
        // Of any at all in one place: of any at all.
        $of = $a->of === [] || $b->of === [] ? [] : array_values(array_unique([...$a->of, ...$b->of]));

        return match ($a->kind) {
            Shape::ENTRY => Shape::entry($of),
            Shape::TERM => Shape::term($of),
            Shape::TERM_PAGE => Shape::termPage($of),
            Shape::VALUES => $a->subject === $b->subject ? Shape::values($a->subject ?? 'collection', $of) : $a,
            default => Shape::block($of),
        };
    }

    /** Code on one line, cut short. */
    private static function brief(string $code): string
    {
        $code = trim((string) preg_replace('/\s+/', ' ', $code));

        return mb_strlen($code) > 60 ? mb_substr($code, 0, 57).'…' : $code;
    }
}
