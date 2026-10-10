<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Applies operations to a template and hands back the new file, or refuses
 * and leaves nothing changed.
 *
 * This is the one way an edit reaches a file. The operations are made on
 * the tree; what the tree then prints must open as a template again and
 * compile, or the whole list is refused with a reason for the person.
 *
 * Operations name nodes of the version they were written for. In a list,
 * a later one can name any node an earlier one left in place, but not one
 * it removed, rewrote, or moved to another parent: ask again with the new
 * file for those.
 */
final class Operations
{
    /** Directives that only compile inside a loop or a @switch. */
    private const NEEDS_LOOP = ['break', 'continue'];

    private const LOOPS = ['foreach', 'forelse', 'for', 'while', 'switch'];

    /**
     * @param  array<array-key, Operation|array<array-key, mixed>>  $operations  in the order to apply
     * @return string the template after the operations
     *
     * @throws EditRejected
     */
    public static function apply(string $source, array $operations): string
    {
        return self::run($source, $operations)->source;
    }

    /**
     * @param  array<array-key, Operation|array<array-key, mixed>>  $operations  in the order to apply
     *
     * @throws EditRejected
     */
    public static function run(string $source, array $operations): Applied
    {
        try {
            $template = Template::open($source);
        } catch (Unsupported $e) {
            throw new EditRejected('This template can only be edited as code: '.lcfirst($e->getMessage()).'.');
        }

        return self::runOn($template, $operations);
    }

    /**
     * The same for a template that is already open. Its tree is used up by
     * this, whether the operations are accepted or not: open the result,
     * or the source again, to go on.
     *
     * @param  array<array-key, Operation|array<array-key, mixed>>  $operations  in the order to apply
     *
     * @throws EditRejected
     */
    public static function runOn(Template $template, array $operations): Applied
    {
        // Everything is read and every node found before anything changes.
        $steps = [];
        foreach (array_values($operations) as $index => $data) {
            try {
                $operation = Operation::from($data);
                $steps[] = [
                    $operation,
                    self::find($template, $operation->node),
                    $operation->type === 'move' ? self::find($template, $operation->text('target')) : null,
                ];
            } catch (EditRejected $e) {
                throw self::at($e, $index);
            }
        }
        $orphans = count(self::orphans($template->root));

        $focus = null;
        foreach ($steps as $index => [$operation, $node, $target]) {
            try {
                self::mustBeThere($template, $node);
                if ($target !== null) {
                    self::mustBeThere($template, $target);
                }
                $focus = self::perform($operation, $node, $target);
            } catch (EditRejected $e) {
                throw self::at($e, $index);
            }
        }

        $out = $template->print();
        if ($out === $template->source) {
            return new Applied($out, $focus === null ? null : self::idIn($template->root, $focus));
        }

        return new Applied($out, self::check($template, $out, $orphans, $focus));
    }

    /**
     * Refuses a result that can't be saved, and returns the id of the node
     * to select in it.
     */
    private static function check(Template $template, string $out, int $orphans, ?Node $focus): ?string
    {
        try {
            $result = Template::open($out);
        } catch (Unsupported $e) {
            throw new ResultRejected('That would leave a template the editor can\'t open: '.lcfirst($e->getMessage()).'.');
        }

        // PHP's parser lets a stray "break" through; running the page would not.
        $stray = self::orphans($result->root);
        if (count($stray) > $orphans) {
            throw new ResultRejected("That would leave @{$stray[0]->name} with no loop around it.");
        }

        $error = Syntax::error($out);
        if ($error !== null) {
            if (Syntax::error($template->source) === null) {
                throw new ResultRejected('That would leave a template that doesn\'t compile: '.$error.'.');
            }
            // The file had a problem before, such as a component this
            // application doesn't have. Its PHP still has to be sound.
            $error = Syntax::error($out, components: false);
            if ($error !== null) {
                throw new ResultRejected('The template doesn\'t compile: '.$error.'.');
            }
        }

        if ($focus === null || ($id = self::idIn($template->root, $focus)) === null) {
            return null;
        }

        // Text that ended up beside other text is one node with it now.
        return $result->find($id)?->type === $focus->type ? $id : null;
    }

    /** Makes one edit and returns the node it made or changed, if there is one left. */
    private static function perform(Operation $operation, Node $node, ?Node $target): ?Node
    {
        switch ($operation->type) {
            case 'setAttribute':
                Edit::setAttribute($node, $operation->text('name'), $operation->textOrNull('value'), $operation->flag('blade'));

                return $node;
            case 'removeAttribute':
                Edit::removeAttribute($node, $operation->text('name'));

                return $node;
            case 'addClass':
                Edit::addClass($node, $operation->text('class'));

                return $node;
            case 'removeClass':
                Edit::removeClass($node, $operation->text('class'));

                return $node;
            case 'setId':
                Edit::setId($node, $operation->text('id'));

                return $node;
            case 'setTag':
                Edit::setTag($node, $operation->text('tag'));

                return $node;
            case 'setText':
                Edit::setText($node, $operation->text('text'));

                return $node;
            case 'setExpression':
                Edit::setExpression($node, $operation->text('expression'));

                return $node;
            case 'setEchoRaw':
                Edit::setEchoRaw($node, $operation->flag('raw'));

                return $node;
            case 'setArguments':
                Edit::setArguments($node, $operation->textOrNull('arguments'));

                return $node;
            case 'setCode':
                return Edit::setCode($node, $operation->text('code'))[0] ?? null;
            case 'insertBefore':
                return Edit::insertBefore($node, $operation->text('blade'))[0] ?? null;
            case 'insertAfter':
                return Edit::insertAfter($node, $operation->text('blade'))[0] ?? null;
            case 'append':
                return Edit::append($node, $operation->text('blade'))[0] ?? null;
            case 'prepend':
                return Edit::prepend($node, $operation->text('blade'))[0] ?? null;
            case 'remove':
                Edit::remove($node);

                return null;
            case 'duplicate':
                return Edit::duplicate($node);
            case 'move':
                return Edit::move($node, $target ?? $node, $operation->text('position'));
            case 'wrap':
                return Edit::wrap($node, $operation->text('wrapper'));
            case 'unwrap':
                return Edit::unwrap($node)[0] ?? null;
            default:
                throw new EditRejected("There is no operation \"{$operation->type}\".");
        }
    }

    private static function find(Template $template, string $id): Node
    {
        return $template->find($id)
            ?? throw new EditRejected("There is no node \"{$id}\" in this version of the template. It may have changed since it was opened.");
    }

    /** A node an earlier operation took out of the tree can't be worked on. */
    private static function mustBeThere(Template $template, Node $node): void
    {
        // Up to whatever has no parent: the root, or what was taken out.
        $top = $node;
        while ($top->parent !== null) {
            $top = $top->parent;
        }
        if ($top !== $template->root) {
            throw new EditRejected("An earlier operation removed, rewrote or moved node \"{$node->id()}\". Apply what came before it first, then go on with the new file.");
        }
    }

    /** Says which operation of the list was refused. */
    private static function at(EditRejected $e, int $index): EditRejected
    {
        $e->operation ??= $index;

        return $e;
    }

    /**
     * Each @break and @continue with no loop or @switch around it.
     *
     * @return list<Node>
     */
    private static function orphans(Node $root): array
    {
        $orphans = [];
        foreach ($root->walk() as $node) {
            if ($node->type !== 'atom' || $node->kind !== 'directive' || ! in_array(strtolower($node->name), self::NEEDS_LOOP, true)) {
                continue;
            }
            for ($up = $node->parent; $up !== null; $up = $up->parent) {
                if ($up->type === 'block' && in_array(strtolower($up->name), self::LOOPS, true)) {
                    continue 2;
                }
            }
            $orphans[] = $node;
        }

        return $orphans;
    }

    /** The id a node has in the file its tree prints now: where it starts there. */
    private static function idIn(Node $root, Node $node): ?string
    {
        $offset = self::offset($root, $node, 0);

        return match (true) {
            $offset === null => null,
            $node->type === 'root' => 'root',
            $node->type === 'branch' => 'b'.$offset,
            default => (string) $offset,
        };
    }

    private static function offset(Node $in, Node $node, int $offset): ?int
    {
        if ($in === $node) {
            return $offset;
        }
        $offset += strlen($in->open);
        foreach ($in->children as $child) {
            $found = self::offset($child, $node, $offset);
            if ($found !== null) {
                return $found;
            }
            $offset += strlen($child->print());
        }

        return null;
    }
}
