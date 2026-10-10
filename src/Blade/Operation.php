<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

use JsonSerializable;

/**
 * One edit as plain data: what to do, to which node, with what. This is
 * what the editor's screen sends and what an AI agent writes.
 *
 *     ['type' => 'addClass', 'node' => '412', 'class' => 'hero']
 *     ['type' => 'move', 'node' => '412', 'target' => 'b96', 'position' => 'inside']
 *
 * Nodes are named by their id in the version of the file the operation was
 * written for (see Node::id()). There is one type for each method of Edit,
 * with the same name, taking the same things in the same order.
 */
final class Operation implements JsonSerializable
{
    /**
     * What each type takes besides its node, in the order Edit takes them:
     * text, text or null (?text), yes or no (flag), or another node.
     */
    private const TYPES = [
        'setAttribute' => ['name' => 'text', 'value' => '?text', 'blade' => 'flag'],
        'removeAttribute' => ['name' => 'text'],
        'addClass' => ['class' => 'text'],
        'removeClass' => ['class' => 'text'],
        'setId' => ['id' => 'text'],
        'setTag' => ['tag' => 'text'],
        'setText' => ['text' => 'text'],
        'setExpression' => ['expression' => 'text'],
        'setEchoRaw' => ['raw' => 'flag'],
        'setArguments' => ['arguments' => '?text'],
        'setCode' => ['code' => 'text'],
        'insertBefore' => ['blade' => 'text'],
        'insertAfter' => ['blade' => 'text'],
        'append' => ['blade' => 'text'],
        'prepend' => ['blade' => 'text'],
        'remove' => [],
        'duplicate' => [],
        'move' => ['target' => 'node', 'position' => 'text'],
        'wrap' => ['wrapper' => 'text'],
        'unwrap' => [],
    ];

    /** What may be left out, and what it is then. */
    private const DEFAULTS = ['setAttribute' => ['blade' => false]];

    /**
     * @param  array<string, string|bool|null>  $parameters
     */
    private function __construct(
        public readonly string $type,
        public readonly string $node,
        public readonly array $parameters,
    ) {}

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * What a type takes besides its node, in order: name => text, ?text, flag or node.
     *
     * @return array<string, string>
     */
    public static function takes(string $type): array
    {
        return self::TYPES[$type] ?? [];
    }

    /**
     * @param  array<array-key, mixed>  $parameters  by name
     */
    public static function make(string $type, string $node, array $parameters = []): self
    {
        return self::from(['type' => $type, 'node' => $node] + $parameters);
    }

    /**
     * Reads an operation from data, refusing what it doesn't understand:
     * an unknown type, something missing, something of the wrong kind, or
     * something the type doesn't take.
     *
     * @param  self|array<array-key, mixed>  $data
     */
    public static function from(self|array $data): self
    {
        if ($data instanceof self) {
            return $data;
        }

        $type = $data['type'] ?? null;
        if (! is_string($type) || ! isset(self::TYPES[$type])) {
            throw new EditRejected(is_string($type) ? "There is no operation \"{$type}\"." : 'An operation needs a "type".');
        }
        $node = $data['node'] ?? null;
        if (! is_string($node) && ! is_int($node)) {
            throw new EditRejected("{$type} needs a \"node\": the id of the node it is about.");
        }

        $parameters = [];
        foreach (self::TYPES[$type] as $name => $kind) {
            if (! array_key_exists($name, $data)) {
                if (! array_key_exists($name, self::DEFAULTS[$type] ?? [])) {
                    throw new EditRejected("{$type} needs \"{$name}\".");
                }
                $parameters[$name] = self::DEFAULTS[$type][$name];

                continue;
            }
            $value = $data[$name];
            $parameters[$name] = match (true) {
                $kind === 'flag' && is_bool($value) => $value,
                $kind === 'node' && (is_string($value) || is_int($value)) => (string) $value,
                $kind === 'text' && is_string($value) => $value,
                $kind === '?text' && (is_string($value) || $value === null) => $value,
                default => throw new EditRejected("\"{$name}\" of {$type} has to be ".match ($kind) {
                    'flag' => 'true or false',
                    'node' => 'the id of a node',
                    '?text' => 'text or null',
                    default => 'text',
                }.'.'),
            };
        }

        foreach (array_keys($data) as $name) {
            if (! in_array($name, ['type', 'node'], true) && ! isset(self::TYPES[$type][$name])) {
                throw new EditRejected("{$type} doesn't take \"{$name}\".");
            }
        }

        return new self($type, (string) $node, $parameters);
    }

    public function text(string $name): string
    {
        return (string) ($this->parameters[$name] ?? '');
    }

    public function textOrNull(string $name): ?string
    {
        $value = $this->parameters[$name] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function flag(string $name): bool
    {
        return ($this->parameters[$name] ?? false) === true;
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'node' => $this->node] + $this->parameters;
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
