<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

/** Only the reference panel's data; completion/operation metadata stays in the full catalog. */
final class Reference
{
    /** @param list<array<string, mixed>> $variables
     * @return list<array<string, mixed>>
     */
    public static function variables(array $variables): array
    {
        return array_map(self::row(...), $variables);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $reference = array_intersect_key($row, array_flip(['code', 'title', 'label', 'what', 'of', 'from', 'sure', 'note', 'inserts', 'print']));
        // Every printable example already occurs in inserts; keep only the fallback.
        if ($reference['inserts'] !== []) {
            $reference['print'] = null;
        }
        $reference['groups'] = array_map(fn (array $group): array => [...$group, 'fields' => array_map(self::row(...), $group['fields'])], $row['groups']);

        return $reference;
    }
}
