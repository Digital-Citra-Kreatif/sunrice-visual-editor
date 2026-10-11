<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Changes to an element's opening tag: attributes, classes, the tag name.
 * Each one rewrites the smallest piece of the tag that says what changed and
 * leaves the rest of it, spacing and quoting included, as it was written.
 */
final class Attributes
{
    private const NAME = '/^[A-Za-z_:@.][A-Za-z0-9_:.@-]*$/';

    private const TAG = '/^[A-Za-z][A-Za-z0-9-]*$/';

    /**
     * Sets an attribute. A null value writes the bare name (`disabled`).
     * The value is plain text unless $blade is true, when it is written as
     * given: `{{ $entry->url }}`.
     */
    public static function set(Node $element, string $name, ?string $value, bool $blade = false): void
    {
        $name = self::name($name);
        $written = $value === null ? null : ($blade ? $value : Inert::attribute($value));
        $attr = self::existing($element, $name);
        $open = $element->open;

        if ($attr === null) {
            $at = self::insertPoint($element);
            $new = substr($open, 0, $at).' '.$name.($written === null ? '' : '="'.$written.'"').substr($open, $at);
        } elseif ($written === null) {
            // Drop the value, keep the name.
            $nameEnd = $attr['start'] + strlen($attr['name']);
            $new = substr($open, 0, $nameEnd).substr($open, $attr['end']);
        } elseif ($attr['valueStart'] === null || $attr['valueEnd'] === null) {
            $new = substr($open, 0, $attr['end']).'="'.$written.'"'.substr($open, $attr['end']);
        } elseif (self::quoted($open, $attr)) {
            $quote = $open[$attr['valueEnd']];
            if ($blade && str_contains(self::outsideBlade($written), $quote)) {
                throw new EditRejected("The value can't contain {$quote} outside {{ }}: the attribute is written with that quote.");
            }
            $new = substr($open, 0, $attr['valueStart']).$written.substr($open, $attr['valueEnd']);
        } else {
            // An unquoted value: quote the new one.
            $new = substr($open, 0, $attr['valueStart']).'"'.$written.'"'.substr($open, $attr['valueEnd']);
        }

        self::commit($element, $new, $name, $written);
    }

    public static function remove(Node $element, string $name): void
    {
        $name = self::name($name);
        $attr = self::existing($element, $name);
        if ($attr === null) {
            return;
        }

        // The attribute and the whitespace before it.
        $from = $attr['start'];
        while ($from > 0 && ctype_space($element->open[$from - 1])) {
            $from--;
        }

        self::commit($element, substr($element->open, 0, $from).substr($element->open, $attr['end']), $name, false);
    }

    /** @return list<string> the classes written as plain words (not those Blade prints) */
    public static function classes(Node $element): array
    {
        return array_column(self::classTokens($element), 'class');
    }

    public static function addClass(Node $element, string $class): void
    {
        $class = self::className($class);
        self::refuseForComponent($element);
        self::refuseClassDirective($element);
        if (in_array($class, self::classes($element), true)) {
            return;
        }

        $attr = self::existing($element, 'class');
        if ($attr === null) {
            // First in the tag, where a class is usually written.
            $at = 1 + strlen($element->name);
            $new = substr($element->open, 0, $at).' class="'.$class.'"'.substr($element->open, $at);
        } elseif ($attr['valueStart'] !== null && $attr['valueEnd'] !== null && self::quoted($element->open, $attr)) {
            $value = substr($element->open, $attr['valueStart'], $attr['valueEnd'] - $attr['valueStart']);
            $new = substr($element->open, 0, $attr['valueEnd']).(rtrim($value) === '' ? '' : ' ').$class.substr($element->open, $attr['valueEnd']);
        } else {
            throw new EditRejected('The class attribute of this element is not a plain quoted list.');
        }

        self::commit($element, $new, 'class', null, check: false);
    }

    public static function removeClass(Node $element, string $class): void
    {
        self::refuseForComponent($element);
        $class = self::className($class);
        self::refuseClassDirective($element);
        $tokens = self::classTokens($element);
        $index = array_search($class, array_column($tokens, 'class'), true);
        if ($index === false) {
            return;
        }

        $attr = self::existing($element, 'class');
        $open = $element->open;
        $valueStart = (int) $attr['valueStart'];
        $valueEnd = (int) $attr['valueEnd'];
        $value = substr($open, $valueStart, $valueEnd - $valueStart);

        if (count($tokens) === 1 && trim($value) === $class) {
            // The only class: the attribute goes with it.
            self::remove($element, 'class');

            return;
        }

        // The word and one neighbouring run of whitespace: the one after
        // it, or the one before when it is the last word.
        [$from, $to] = [$tokens[$index]['start'], $tokens[$index]['end']];
        $after = strspn($value, " \t\r\n", $to);
        if ($to + $after < strlen($value)) {
            $to += $after;
        } else {
            while ($from > 0 && ctype_space($value[$from - 1])) {
                $from--;
            }
        }

        self::commit($element, substr($open, 0, $valueStart + $from).substr($open, $valueStart + $to), 'class', null, check: false);
    }

    /** Renames the element: both of its tags. */
    public static function setTag(Node $element, string $tag): void
    {
        if (preg_match(self::TAG, $tag) !== 1) {
            throw new EditRejected("\"{$tag}\" is not a tag name.");
        }
        $tag = strtolower($tag);
        if ($element->type !== 'element') {
            throw new EditRejected($element->stray !== null ? 'This tag\'s other half is somewhere else: rename both in the code.' : 'Only elements have a tag to rename.');
        }
        if ($tag === $element->name) {
            return;
        }

        $void = in_array($tag, Node::VOID, true);
        if ($void !== ($element->close === '')) {
            throw new EditRejected($void ? "<{$tag}> can't hold anything, and this element has content." : "<{$element->name}> has no closing tag to rename.");
        }
        foreach ([$tag, $element->name] as $name) {
            if (in_array($name, HtmlLexer::RAW_TEXT, true)) {
                throw new EditRejected("<{$name}> holds code, not markup: it can't be renamed to or from.");
            }
        }

        // Inside <svg> and <math> a tag may close itself (<path />); outside it may not.
        foreach ([$tag, $element->name] as $name) {
            if (in_array($name, ['svg', 'math'], true)) {
                throw new EditRejected("<{$name}> changes how the tags inside it are read: it can't be renamed to or from.");
            }
        }

        $old = strlen($element->name);
        $close = $element->close === '' ? '' : '</'.$tag.substr($element->close, 2 + $old);
        $open = '<'.$tag.substr($element->open, 1 + $old);

        $closeBefore = $element->close;
        $element->close = $close;
        try {
            self::commit($element, $open, null, null, check: false);
        } catch (EditRejected $e) {
            $element->close = $closeBefore;

            throw $e;
        }
    }

    /**
     * Reads the opening tag again after a change, so the element's name and
     * attribute positions are right for the next edit.
     */
    public static function refresh(Node $element): void
    {
        // A component's tag is read as an element's would be: under another name, of the same
        // length, that Blade does not take for a component and read as one piece.
        $component = Rules::isComponentTag($element);
        $open = $component ? '<y-'.substr($element->open, 3) : $element->open;
        $tokens = (new HtmlLexer)->lex($open, (new BladeScanner)->scan($open));
        $tag = $tokens[0] ?? null;
        if (count($tokens) !== 1 || $tag['type'] !== 'open' || $tag['start'] !== 0 || $tag['end'] !== strlen($open)) {
            throw new EditRejected($component ? 'The component\'s tag can\'t be read attribute by attribute: change it in the code.' : 'The change would break the tag.');
        }
        if (! $component) {
            $element->name = $tag['name'];
        }
        $element->attrs = $tag['attrs'];
    }

    /**
     * Puts the new opening tag in place if it still reads as one tag and,
     * for a set or removed attribute, says what was meant.
     *
     * @param  string|false|null  $expected  the attribute's value as written; null for a bare name; false when it must be gone
     */
    private static function commit(Node $element, string $open, ?string $name, string|false|null $expected, bool $check = true): void
    {
        $before = [$element->open, $element->name, $element->attrs];
        $element->open = $open;

        try {
            self::refresh($element);
            if ($check && $name !== null) {
                $attr = $element->attr($name);
                $ok = $expected === false
                    ? $attr === null
                    : $attr !== null && $element->attributeValue($name) === $expected;
                if (! $ok) {
                    throw new EditRejected('The value would not stay inside the attribute.');
                }
            }
        } catch (EditRejected|Unsupported $e) {
            [$element->open, $element->name, $element->attrs] = $before;

            throw $e instanceof EditRejected ? $e : new EditRejected('The change would break the tag: '.$e->getMessage());
        }
    }

    private static function name(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new EditRejected("\"{$name}\" is not an attribute name.");
        }

        return strtolower($name);
    }

    private static function className(string $class): string
    {
        if ($class === '' || preg_match('/[\s"\'<>`={}@]/', $class) === 1) {
            throw new EditRejected("\"{$class}\" is not a class name.");
        }

        return $class;
    }

    /**
     * The attribute as written, or null. Refuses one whose name Blade builds
     * or that appears twice: there is no saying which is meant.
     *
     * @return array{kind: string, name: string, start: int, end: int, valueStart: ?int, valueEnd: ?int, dynamic: bool}|null
     */
    private static function existing(Node $element, string $name): ?array
    {
        if (Rules::isComponentTag($element)) {
            // Read where it is needed: the tree keeps a component's tag as one piece.
            self::refresh($element);
        } elseif (! Rules::hasAttributes($element)) {
            throw new EditRejected('Only elements have attributes.');
        }
        $found = array_values(array_filter($element->attrs, fn (array $attr) => $attr['kind'] === 'attr' && $attr['name'] === $name));
        if (count($found) > 1) {
            throw new EditRejected("The tag has more than one {$name} attribute.");
        }

        return $found[0] ?? null;
    }

    /** @param array{valueStart: ?int, valueEnd: ?int} $attr */
    private static function quoted(string $open, array $attr): bool
    {
        return $attr['valueEnd'] !== null && in_array($open[$attr['valueEnd']] ?? '', ['"', "'"], true)
            && ($open[$attr['valueStart'] - 1] ?? '') === $open[$attr['valueEnd']];
    }

    /** Where a new attribute goes: after the last thing in the tag. */
    private static function insertPoint(Node $element): int
    {
        $last = end($element->attrs);
        if ($last !== false) {
            return $last['end'];
        }

        // After the tag's name: "<section", "<x-sunrice::entries".
        return Rules::isComponentTag($element) ? strcspn($element->open, " \t\r\n/>") : 1 + strlen($element->name);
    }

    /** A component's classes are whatever its `class` attribute says: one text, set as an attribute. */
    private static function refuseForComponent(Node $element): void
    {
        if (Rules::isComponentTag($element)) {
            throw new EditRejected('A component is given its classes as one attribute: change its class attribute.');
        }
    }

    private static function refuseClassDirective(Node $element): void
    {
        if (! Rules::hasAttributes($element)) {
            throw new EditRejected('Only elements have classes.');
        }
        foreach ($element->attrs as $attr) {
            if ($attr['kind'] === 'code' && strtolower($attr['name']) === '@class') {
                throw new EditRejected('The classes of this element are set by @class([…]): edit that instead.');
            }
        }
    }

    /**
     * The class words that are plain text, with their positions in the
     * attribute's value. Words Blade prints, or builds part of, are skipped.
     *
     * @return list<array{class: string, start: int, end: int}>
     */
    private static function classTokens(Node $element): array
    {
        $value = $element->attributeValue('class');
        if ($value === null) {
            return [];
        }
        $masked = self::outsideBlade($value, keepLength: true);

        $tokens = [];
        preg_match_all('/\S+/', $masked, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$word, $offset]) {
            if (! str_contains($word, "\x1A")) {
                $tokens[] = ['class' => $word, 'start' => $offset, 'end' => $offset + strlen($word)];
            }
        }

        return $tokens;
    }

    /**
     * The text with every Blade construct taken out, or with $keepLength
     * replaced by a filler of the same length so positions still match.
     */
    private static function outsideBlade(string $text, bool $keepLength = false): string
    {
        $atoms = (new BladeScanner)->scan($text);
        foreach (array_reverse($atoms) as $atom) {
            $length = $atom->end - $atom->start;
            $text = substr_replace($text, $keepLength ? str_repeat("\x1A", $length) : '', $atom->start, $length);
        }

        return $text;
    }
}
