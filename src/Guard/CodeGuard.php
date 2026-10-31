<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Guard;

use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Operation;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Blade\Rules;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Fields\Picker;
use Sunrice\VisualEditor\Insert\Library;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Throwable;

/**
 * Holds a change by someone who may edit but not edit code to this: the
 * template runs no PHP after it that it did not run before, except code
 * the editor writes itself.
 *
 * It is two checks, and the second does not rely on the first.
 *
 * 1. What may be new. Each operation is looked at for the code it brings,
 *    and that code counts only when the editor offers exactly it at that
 *    place: a field from the picker, something from the Insert library, a
 *    condition or a loop built from choices, a plain value for one of
 *    Sunrice's components. What was typed is no such thing.
 * 2. What is new. The template before and after is compiled by Laravel's
 *    own Blade compiler, and every piece of PHP in the result has to be
 *    one that was there before, or one that compiling the code counted
 *    in (1) gives. Whatever way a change found to make PHP — through an
 *    operation this class has no rule for, or a fault in how another
 *    writes text — it shows here, and the change is refused.
 *
 * Code that is there already may be moved, copied and taken out: that is
 * editing, and nobody's new code.
 */
final class CodeGuard
{
    /** What an element can be wrapped in from the Settings panel (WRAPPERS in lib/move.ts). */
    public const WRAPPERS = ['<div></div>', '<section></section>', '<a href="#"></a>', "@if (true)\n@endif"];

    private const HTML_TAGS = 'a abbr address article aside b blockquote br button caption code col colgroup dd del details div dl dt em figcaption figure footer form h1 h2 h3 h4 h5 h6 header hr i img input label li main nav ol option p picture pre section select small source span strong sub summary sup table tbody td textarea th thead time tr ul video';

    /** A name for a loop's item: a variable, and none that Blade or PHP keep for themselves. */
    private const ITEM = '/^\$(?!this\b|__)[A-Za-z_]\w*$/';

    public function __construct(private readonly Picker $picker, private readonly Library $library) {}

    /**
     * @param  array<array-key, Operation|array<array-key, mixed>>  $operations  as they were applied to $before
     *
     * @throws CodeRefused
     */
    public function operations(TemplateFile $file, string $before, array $operations, string $after): void
    {
        $template = Template::open($before);
        $operations = array_map(Operation::from(...), array_values($operations));
        $offered = $this->offered($file, $template, $before, $operations);
        $this->markup($template, $operations, array_column($offered, 0));
        $this->rawLiterals($before, $after);
        $new = $this->added($before, $after);
        if ($new === []) {
            return;
        }

        $counted = [];
        try {
            foreach ($offered as [$blade, $most]) {
                // Nothing the editor writes has a PHP tag in it. Blade would run one wherever it stood, in a quoted text too.
                if (str_contains($blade, '<?')) {
                    continue;
                }
                try {
                    $islands = Compiled::islands($blade);
                    // A condition or a loop is one piece of PHP: more than that is something else riding along.
                    if ($most === null || count($islands) <= $most) {
                        $counted += $islands;
                    }
                } catch (Throwable) {
                    // Code that does not compile on its own counts for nothing.
                }
            }
        } catch (Unsupported|EditRejected) {
            // Nothing can be said of what was offered: nothing counts.
        }

        $this->refuse(array_diff_key($new, $counted));
    }

    /**
     * A template's whole text written by someone who may not edit code:
     * raw source needs code permission; exact historical versions are authorized by the caller.
     *
     * @throws CodeRefused
     */
    public function source(string $before, string $after): void
    {
        $this->refuse($this->added($before, $after));
        if ($before !== $after) {
            throw new CodeRefused('Writing template source requires visual-editor.code. Use the offered operations, or restore an exact saved version.');
        }
    }

    /** A scalar typed into an echo must not bypass the markup restriction by becoming raw. */
    private function rawLiterals(string $before, string $after): void
    {
        $known = [];
        foreach (Template::open($before)->root->walk() as $node) {
            if ($node->kind === 'raw_echo') {
                $known[$node->source()] = true;
            }
        }
        foreach (Template::open($after)->root->walk() as $node) {
            if ($node->kind !== 'raw_echo' || $node->args === null || isset($known[$node->source()])) {
                continue;
            }
            $expression = trim($node->args);
            $literal = preg_match('/^__\((.+)\)$/s', $expression, $match) === 1 ? $match[1] : $expression;
            if (Literal::isScalar($literal)) {
                throw new CodeRefused('Raw literal output requires visual-editor.code. Use escaped text instead.');
            }
        }
    }

    /**
     * Raw markup is code too: inserts must come from the editor's lists.
     * Literal HTML attributes have a deliberately small, non-executable surface.
     *
     * @param  list<Operation>  $operations
     * @param  list<string>  $offered
     */
    private function markup(Template $template, array $operations, array $offered): void
    {
        $tags = explode(' ', self::HTML_TAGS);
        foreach ($operations as $operation) {
            $node = $template->find($operation->node);
            $allowed = match ($operation->type) {
                'insertBefore', 'insertAfter', 'append', 'prepend' => in_array(trim($operation->text('blade')), array_map(trim(...), $offered), true),
                'wrap' => in_array($operation->text('wrapper'), self::WRAPPERS, true),
                'setCode' => false,
                'setTag' => in_array(strtolower($operation->text('tag')), $tags, true),
                'setAttribute' => $node !== null && $this->plainAttribute($node, $operation),
                default => true,
            };
            // Text and literal expressions inside executable elements are code, even
            // if no PHP is added. Work against the original tree for every operation.
            if (in_array($operation->type, ['setText', 'setExpression', 'setTag', 'setAttribute', 'append', 'prepend', 'insertBefore', 'insertAfter'], true)) {
                for ($at = $node; $at !== null; $at = $at->parent) {
                    if (in_array(strtolower($at->name), ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
                        $allowed = false;
                    }
                }
            }
            if (! $allowed) {
                throw new CodeRefused('Raw markup adds code, and you may not edit code without visual-editor.code. Choose an offered insert or wrapper instead.');
            }
        }
    }

    private function plainAttribute(Node $node, Operation $operation): bool
    {
        $name = strtolower($operation->text('name'));
        $browserName = ltrim($name, ':');
        if (str_starts_with($browserName, 'on') || $browserName === 'srcdoc' || str_starts_with($name, 'x-') || str_starts_with($name, '@')) {
            return false;
        }
        if (Rules::isComponentTag($node)) {
            return str_starts_with($node->open, '<x-sunrice-cms::');
        }
        $safe = in_array($name, ['id', 'class', 'title', 'alt', 'role', 'width', 'height', 'controls', 'rows', 'cols', 'colspan', 'rowspan', 'for', 'target', 'rel', 'type', 'name', 'value', 'placeholder', 'method', 'action', 'disabled', 'checked', 'selected', 'tabindex', 'href', 'src'], true)
            || str_starts_with($name, 'aria-');
        if (! $safe || ! in_array($name, ['href', 'src', 'action'], true)) {
            return $safe;
        }
        // Browser URL schemes are case-insensitive and ignore ASCII whitespace.
        // Decode entities before checking; typed ampersands are escaped on output.
        $value = html_entity_decode($operation->text('value'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x20\x7f]+/', '', $value) ?? '';

        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) !== 1 || preg_match('/^(https?|mailto|tel):/i', $value) === 1;
    }

    /**
     * The PHP the template would run after the change and did not before.
     *
     * @return array<string, true>
     */
    private function added(string $before, string $after): array
    {
        try {
            if (Compiled::strayTags($after) > Compiled::strayTags($before)) {
                // Not code PHP runs, and no way to write a template: Blade has taken a `@php` and an `@endphp` for a pair that are none.
                throw new CodeRefused('That would leave PHP tags in the text of the page, which is what Blade makes of a @php and an @endphp that do not belong together. You may not edit code: ask someone who may.');
            }

            return array_diff_key(Compiled::islands($after), Compiled::islands($before));
        } catch (CodeRefused $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CodeRefused('What this template runs can\'t be worked out, so it can only be changed by someone who may edit code: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, true>  $islands
     */
    private function refuse(array $islands): void
    {
        if ($islands === []) {
            return;
        }
        $code = (string) preg_replace('/\s+/', ' ', (string) array_key_first($islands));

        throw new CodeRefused('That would add code to the template, and you may not edit code: '.mb_strimwidth($code, 0, 160, '…').' Pick a field from the list in place of typing it, or ask someone who may edit code.');
    }

    // --- What the editor offers -----------------------------------------------------

    /**
     * The Blade the operations bring that the editor itself offers where
     * they bring it.
     *
     * @param  list<Operation>  $operations
     * @return list<array{0: string, 1: int|null}> each piece of Blade, and how many pieces of PHP it may come to at most (null: as many as it does)
     */
    private function offered(TemplateFile $file, Template $template, string $before, array $operations): array
    {
        $offered = [];
        /** @var array<string, list<Operation>> $onComponents */
        $onComponents = [];
        $library = null;
        $picked = [];
        /** What the picker lists at a node: asked once for each place. */
        $at = function (?Node $node, bool $inside) use ($file, $template, &$picked): array {
            return $picked[($node?->id() ?? 'root').($inside ? ':in' : '')] ??= self::flat($this->picker->at($file, $template, $node, $inside));
        };

        foreach ($operations as $operation) {
            $node = $template->find($operation->node);
            if ($node === null) {
                continue;
            }
            switch ($operation->type) {
                case 'insertBefore':
                case 'insertAfter':
                case 'append':
                case 'prepend':
                    $blade = trim($operation->text('blade'));
                    $library ??= array_map(fn (array $entry) => trim($entry['blade']), $this->library->all(code: false));
                    $inside = in_array($operation->type, ['append', 'prepend'], true);
                    if (in_array($blade, $library, true) || isset($at($node->type === 'root' ? null : $node, $inside)['blades'][$blade])) {
                        $offered[] = [$blade, null];
                    }
                    break;

                case 'setExpression':
                    $expression = trim($operation->text('expression'));
                    // A field from the picker, or a text written out: "Value", an interface text.
                    $plain = preg_match('/^__\((.+)\)$/s', $expression, $match) === 1 ? Literal::isScalar($match[1]) : Literal::isScalar($expression);
                    if ($plain || isset($at($node, false)['expressions'][$expression])) {
                        // Printed as text or as HTML: which of the two is the next operation's to say.
                        $offered[] = ['{{ '.$expression.' }}', 1];
                        $offered[] = ['{!! '.$expression.' !!}', 1];
                    }
                    break;

                case 'setEchoRaw':
                    // The same expression, printed the other way.
                    if ($node->type === 'atom' && in_array($node->kind, ['echo', 'raw_echo'], true) && $node->args !== null) {
                        $offered[] = ['{{ '.trim($node->args).' }}', 1];
                        $offered[] = ['{!! '.trim($node->args).' !!}', 1];
                    }
                    break;

                case 'setAttribute':
                case 'removeAttribute':
                    if (Rules::isComponentTag($node)) {
                        // The CMS's own components take plain values. What another component makes of what it is given is not known here.
                        if (str_starts_with($node->open, '<x-sunrice-cms::')) {
                            $onComponents[$operation->node][] = $operation;
                        }
                    } elseif ($operation->type === 'setAttribute') {
                        $value = trim((string) $operation->parameters['value']);
                        if (preg_match('/^\{\{ (.+) \}\}$/s', $value, $match) === 1 && isset($at($node, false)['expressions'][$match[1]])) {
                            $offered[] = [$value, 1];
                        }
                    }
                    break;

                case 'setArguments':
                    $arguments = trim((string) $operation->parameters['arguments']);
                    $name = strtolower($node->name);
                    // Asked about at the directive, or at the block it opens: the panel may have asked at either.
                    $here = $at($node, false);
                    if ($node->type === 'branch' && $node->parent !== null) {
                        $around = $at($node->parent, false);
                        $here = ['asked' => [...$here['asked'], ...$around['asked']], 'looped' => $here['looped'] + $around['looped']] + $here;
                    }
                    if (in_array($name, ['if', 'elseif'], true) && self::isCondition($arguments, $here['asked'])) {
                        $offered[] = ['@'.$name.' ('.$arguments.')', 1];
                    } elseif (in_array($name, ['foreach', 'forelse'], true) && self::isLoop($arguments, $here['looped'])) {
                        $offered[] = ['@'.$name.' ('.$arguments.')', 1];
                    }
                    break;

                case 'wrap':
                    if (in_array($operation->text('wrapper'), self::WRAPPERS, true)) {
                        $offered[] = [$operation->text('wrapper'), null];
                    }
                    break;
            }
        }

        // What a component is given, when all of it is plain: the component as these changes alone leave it.
        foreach ($onComponents as $changes) {
            if (array_filter($changes, fn (Operation $change) => ! self::isPlainlyGiven($change)) !== []) {
                continue;
            }
            try {
                $alone = Operations::run($before, $changes);
                $changed = $alone->focus === null ? null : Template::open($alone->source)->find($alone->focus);
                // The tag, or the whole component when the tag is the arm it opens with.
                $whole = $changed?->type === 'branch' ? $changed->parent : $changed;
                if ($whole !== null) {
                    $offered[] = [$whole->print(), null];
                }
            } catch (EditRejected|Unsupported) {
                // Refused on its own account: nothing of it counts.
            }
        }

        return $offered;
    }

    /**
     * What the picker lists at a place, as sets to look things up in.
     *
     * @param  list<array<string, mixed>>  $values  variables, or fields, each with `groups` of fields
     * @return array{blades: array<string, true>, expressions: array<string, true>, asked: list<array{0: string, 1: string}>, looped: array<string, true>}
     */
    private static function flat(array $values): array
    {
        $flat = ['blades' => [], 'expressions' => [], 'asked' => [], 'looped' => []];
        $walk = function (array $values) use (&$walk, &$flat): void {
            foreach ($values as $value) {
                foreach ((array) ($value['inserts'] ?? []) as $insert) {
                    if (is_array($insert) && is_string($insert['blade'] ?? null)) {
                        $flat['blades'][trim($insert['blade'])] = true;
                    }
                }
                if (is_string($value['print'] ?? null)) {
                    $flat['blades'][trim($value['print'])] = true;
                }
                if (is_string($value['expression'] ?? null)) {
                    $flat['expressions'][trim($value['expression'])] = true;
                }
                if (is_string($value['asked'] ?? null) && is_string($value['compared'] ?? null)) {
                    $flat['asked'][] = [$value['asked'], $value['compared']];
                }
                if (is_string($value['looped'] ?? null)) {
                    $flat['looped'][trim($value['looped'])] = true;
                }
                foreach ((array) ($value['groups'] ?? []) as $group) {
                    $walk((array) ($group['fields'] ?? []));
                }
            }
        };
        $walk($values);

        return $flat;
    }

    /**
     * Whether a condition is one the Settings panel builds from choices
     * (`condition()` in lib/builders.ts) about one of the values at the
     * place: is it set, is it this, more than that. What it is compared
     * with is a plain value.
     *
     * @param  list<array{0: string, 1: string}>  $values  each value as it is asked about, and as it is compared
     */
    private static function isCondition(string $code, array $values): bool
    {
        foreach ($values as [$asked, $compared]) {
            if (in_array($code, [$asked, self::not($asked), $compared.'?->isPast()', $compared.'?->isFuture()'], true)) {
                return true;
            }
            $forms = [
                ['str_contains((string) '.$compared.', ', ')'],
                ['in_array(', ', (array) '.$compared.')'],
                ...array_map(fn (string $operator) => [$compared.' '.$operator.' ', ''], ['==', '!=', '>', '<']),
            ];
            foreach ($forms as [$start, $end]) {
                if (str_starts_with($code, $start) && str_ends_with($code, $end) && strlen($code) > strlen($start) + strlen($end)
                    && Literal::isScalar(substr($code, strlen($start), strlen($code) - strlen($start) - strlen($end)))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The opposite of a condition, written as `not()` in lib/builders.ts writes it. */
    private static function not(string $code): string
    {
        $code = trim($code);
        if (str_starts_with($code, '! ')) {
            return substr($code, 2);
        }

        return preg_match('/^\S+$/', $code) === 1 ? '! '.$code : '! ('.$code.')';
    }

    /**
     * Whether what a loop goes over is one of the lists at the place, and
     * each item gets a plain name.
     *
     * @param  array<string, true>  $lists
     */
    private static function isLoop(string $arguments, array $lists): bool
    {
        $as = strrpos($arguments, ' as ');
        if ($as === false || ! isset($lists[trim(substr($arguments, 0, $as))])) {
            return false;
        }
        $names = array_map(trim(...), explode('=>', substr($arguments, $as + 4)));

        return count($names) <= 2 && array_filter($names, fn (string $name) => preg_match(self::ITEM, $name) !== 1) === [];
    }

    /**
     * Whether what a component is given by this change is plain: a text
     * with nothing of Blade or PHP in it, or, where it is read as PHP
     * (`:limit="3"`), a value written out.
     */
    private static function isPlainlyGiven(Operation $change): bool
    {
        if ($change->type === 'removeAttribute') {
            return true;
        }
        $name = $change->text('name');
        $value = $change->parameters['value'];
        if (preg_match('/^:?[A-Za-z][A-Za-z0-9_.-]*$/', $name) !== 1 || ! is_string($value)) {
            return false;
        }

        return str_starts_with($name, ':') ? Literal::is($value) : preg_match('/[{}@<>$`\\\\]/', $value) !== 1;
    }
}
