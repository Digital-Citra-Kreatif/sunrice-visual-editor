<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

/**
 * What the theme's stylesheets say about a class, ready for the CSS panel:
 * each rule that styles it, with its declarations and where they are.
 */
final class Styles
{
    /** The most rules listed for one class. */
    private const MAX_RULES = 60;

    public function __construct(private readonly ThemeRepository $theme) {}

    /**
     * @param  string|null  $class  a class of an element; null for the stylesheets alone
     * @return array<string, mixed>
     */
    public function for(?string $class): array
    {
        $selector = $class === null ? null : Selector::forClass($class);
        $files = [];
        $rules = [];
        $media = [];
        $more = 0;

        foreach ($this->theme->files() as $file) {
            $source = $file->source();
            $readable = mb_check_encoding($source, 'UTF-8');
            $files[] = $this->about($file, $source) + ['reason' => $readable ? null : 'It is not UTF-8 text: save it as UTF-8 in a code editor first.'];
            if ($class === null || $selector === null || ! $readable) {
                continue;
            }
            $sheet = Stylesheet::read($source);
            foreach ($sheet->rules as $rule) {
                if ($rule->at() === 'media' && $rule->open !== null && $rule->close < strlen($source)) {
                    $media[] = ['file' => $file->path, 'id' => $rule->start, 'prelude' => (string) preg_replace('/\s+/', ' ', $rule->prelude)];
                }
            }
            $found = [];
            foreach ($sheet->all() as $rule) {
                if ($rule->open === null || $rule->isAt()) {
                    continue;
                }
                $selectors = $rule->selectors();
                $exact = in_array($selector, $selectors, true);
                if ($exact || array_filter($selectors, fn (string $each) => Selector::mentions($each, $class)) !== []) {
                    $found[] = [$exact, $rule];
                }
            }
            // The rules for the class itself first, then those that only mention it; each in the order of the file.
            usort($found, fn (array $a, array $b) => [$b[0], $a[1]->start] <=> [$a[0], $b[1]->start]);
            foreach ($found as [$exact, $rule]) {
                if (count($rules) >= self::MAX_RULES) {
                    $more++;

                    continue;
                }
                $rules[] = [
                    'file' => $file->path,
                    'id' => $rule->start,
                    'selector' => implode(', ', $rule->selectors()),
                    'within' => array_map(fn (string $around) => (string) preg_replace('/\s+/', ' ', $around), $rule->within()),
                    'exact' => $exact,
                    'line' => substr_count($source, "\n", 0, $rule->start) + 1,
                    'declarations' => array_map(fn (Declaration $each) => [
                        'id' => $each->start,
                        'property' => $each->property,
                        'value' => $each->value,
                    ], $rule->declarations),
                ];
            }
        }

        $own = $this->theme->own();

        return [
            'class' => $class,
            'selector' => $selector,
            'files' => $files,
            'rules' => $rules,
            'more' => $more,
            // The @media blocks at the top of each stylesheet: where a rule for one width goes.
            'media' => $media,
            // Where a rule that does not exist yet is put.
            'own' => $own === null ? null : $this->about($own, $own->source()),
        ];
    }

    /**
     * @return array{path: string, url: string, version: string, exists: bool}
     */
    private function about(ThemeFile $file, string $source): array
    {
        return ['path' => $file->path, 'url' => $file->url, 'version' => ThemeRepository::version($source), 'exists' => $file->exists];
    }
}
