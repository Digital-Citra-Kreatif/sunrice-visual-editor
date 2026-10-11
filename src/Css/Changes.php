<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

/**
 * Applies changes to a stylesheet and hands back the new file, or refuses
 * and leaves nothing changed. This is the one way a change from the editor
 * reaches a CSS file.
 *
 * A change is plain data, as the editor's screen and AI agents send it:
 *
 *   {type: "set",    declaration: 412, value: "2rem"}
 *   {type: "rename", declaration: 412, property: "padding-block"}
 *   {type: "remove", declaration: 412}
 *   {type: "add",    rule: 398, property: "color", value: "red"}
 *   {type: "rule",   selector: ".card", declarations: [["color", "red"]]}
 *
 * A rule and a declaration are named by where they start in the file, in
 * bytes, as the file was when the list was written: every change of a
 * list names places of that same file.
 */
final class Changes
{
    /** The longest property or value, in bytes. */
    private const MAX_TEXT = 10_000;

    /**
     * @param  array<array-key, mixed>  $changes
     *
     * @throws CssRejected
     */
    public static function run(string $source, array $changes): string
    {
        $sheet = Stylesheet::read($source);

        // Everything is read and found before anything changes. The changes are then made from
        // the end of the file to its start, so that the places the others name still hold.
        $steps = [];
        foreach (array_values($changes) as $index => $change) {
            try {
                if (! is_array($change)) {
                    throw new CssRejected('A change has to be an object with a "type".');
                }
                $steps[] = [$index, $change, self::place($sheet, $change)];
            } catch (CssRejected $e) {
                throw self::at($e, $index);
            }
        }
        usort($steps, fn (array $a, array $b) => [$b[2], $a[0]] <=> [$a[2], $b[0]]);

        foreach ($steps as [$index, $change]) {
            try {
                $source = self::make($sheet, $change);
            } catch (CssRejected $e) {
                throw self::at($e, $index);
            }
            $sheet = Stylesheet::read($source);
        }

        return $source;
    }

    /**
     * Where in the file a change cuts: also checks that what it names is there.
     *
     * @param  array<array-key, mixed>  $change
     */
    private static function place(Stylesheet $sheet, array $change): int
    {
        return match ($change['type'] ?? null) {
            'set' => self::declaration($sheet, $change)->valueStart,
            'rename', 'remove' => self::declaration($sheet, $change)->start,
            'add' => self::after(self::rule($sheet, $change)),
            'rule' => PHP_INT_MAX,
            default => throw new CssRejected('"'.(is_string($change['type'] ?? null) ? $change['type'] : '').'" is not a kind of change: set, rename, remove, add or rule.'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $change
     */
    private static function make(Stylesheet $sheet, array $change): string
    {
        return match ($change['type']) {
            'set' => $sheet->withValue(self::declaration($sheet, $change), self::text($change, 'value')),
            'rename' => $sheet->withProperty(self::declaration($sheet, $change), self::text($change, 'property')),
            'remove' => $sheet->without(self::declaration($sheet, $change)),
            'add' => $sheet->withDeclaration(self::rule($sheet, $change), self::text($change, 'property'), self::text($change, 'value')),
            default => $sheet->withRule(self::text($change, 'selector'), self::pairs($change['declarations'] ?? [])),
        };
    }

    /** Where a new declaration of a rule goes, near enough to put the changes in order. */
    private static function after(Rule $rule): int
    {
        $last = $rule->declarations === [] ? null : $rule->declarations[count($rule->declarations) - 1];

        return $last === null ? (int) $rule->open + 1 : $last->valueEnd;
    }

    /**
     * @param  array<array-key, mixed>  $change
     */
    private static function declaration(Stylesheet $sheet, array $change): Declaration
    {
        $at = $change['declaration'] ?? null;
        if (is_int($at)) {
            foreach ($sheet->all() as $rule) {
                foreach ($rule->declarations as $declaration) {
                    if ($declaration->start === $at) {
                        return $declaration;
                    }
                }
            }
        }

        throw new CssRejected('The stylesheet has no declaration there. It may have been changed by the change before this one: load it again.');
    }

    /**
     * @param  array<array-key, mixed>  $change
     */
    private static function rule(Stylesheet $sheet, array $change): Rule
    {
        $at = $change['rule'] ?? null;
        if (is_int($at)) {
            foreach ($sheet->all() as $rule) {
                if ($rule->start === $at && $rule->open !== null) {
                    return $rule;
                }
            }
        }

        throw new CssRejected('The stylesheet has no rule there: load it again.');
    }

    /**
     * @param  array<array-key, mixed>  $change
     */
    private static function text(array $change, string|int $key): string
    {
        $text = $change[$key] ?? null;
        if (! is_string($text)) {
            throw new CssRejected("The change has to say its \"{$key}\", as text.");
        }
        if (strlen($text) > self::MAX_TEXT || ! mb_check_encoding($text, 'UTF-8')) {
            throw new CssRejected("That \"{$key}\" is too long, or not text.");
        }

        return $text;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(mixed $declarations): array
    {
        if (! is_array($declarations)) {
            throw new CssRejected('The "declarations" of a new rule have to be a list of [property, value] pairs.');
        }
        $pairs = [];
        foreach ($declarations as $pair) {
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2) {
                throw new CssRejected('The "declarations" of a new rule have to be a list of [property, value] pairs.');
            }
            $pairs[] = [self::text($pair, 0), self::text($pair, 1)];
        }

        return $pairs;
    }

    private static function at(CssRejected $e, int $index): CssRejected
    {
        $e->change = $index;

        return $e;
    }
}
