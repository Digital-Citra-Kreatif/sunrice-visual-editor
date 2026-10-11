<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Insert;

use Sunrice\VisualEditor\Blade\Variables;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Sunrice\VisualEditor\Templates\TemplateRepository;

/**
 * What the editor offers to insert into a template: elements, Blade
 * structures, Sunrice's components for the content this site has, and the
 * site's own partials.
 *
 * Each is a piece of Blade that stands on its own (a fragment: its tags
 * and blocks closed) and can be drawn where it lands without anything the
 * page doesn't already have: a condition starts as `true`, a loop goes over
 * an empty list, and a component names a collection, taxonomy or form the
 * site really has. The one exception says so: a partial is offered with
 * the variables it needs.
 */
final class Library
{
    public const ELEMENTS = 'Elements';

    public const BLADE = 'Blade';

    public const SUNRICE = 'Sunrice';

    public const PARTIALS = 'Partials';

    public function __construct(private readonly TemplateRepository $templates) {}

    /**
     * Everything, for this site as it is now.
     *
     * @return list<array{id: string, group: string, title: string, hint: string, blade: string, keywords: string}>
     */
    public function all(): array
    {
        return [
            ...self::elements(),
            ...self::blade(),
            ...self::sunrice(SunriceBridge::collections(), SunriceBridge::taxonomies(), SunriceBridge::forms()),
            ...self::partials($this->templates->files()),
        ];
    }

    /** @return list<array{id: string, group: string, title: string, hint: string, blade: string, keywords: string}> */
    public static function elements(): array
    {
        $entry = fn (string $id, string $title, string $hint, string $blade, string $keywords = '') => self::entry('element.'.$id, self::ELEMENTS, $title, $hint, $blade, $keywords);

        return [
            $entry('section', 'Section', '<section>', '<section></section>', 'area part block'),
            $entry('div', 'Container', '<div>', '<div></div>', 'box group wrapper column row'),
            $entry('heading', 'Heading', '<h2>', '<h2>Heading</h2>', 'title h1 h2 h3 h4'),
            $entry('paragraph', 'Paragraph', '<p>', '<p>Text</p>', 'text copy'),
            $entry('text', 'Text', 'words', 'Text', 'plain copy'),
            $entry('link', 'Link', '<a>', '<a href="#">Link</a>', 'anchor url href'),
            $entry('button', 'Button', '<button>', '<button type="button">Button</button>', 'action'),
            $entry('image', 'Image', '<img>', '<img src="" alt="">', 'picture photo'),
            $entry('list', 'List', '<ul>', "<ul>\n    <li>Item</li>\n</ul>", 'bullets items'),
            $entry('numbered-list', 'Numbered list', '<ol>', "<ol>\n    <li>Item</li>\n</ol>", 'ordered steps items'),
            $entry('list-item', 'List item', '<li>', '<li>Item</li>', 'bullet'),
            $entry('span', 'Inline text', '<span>', '<span>Text</span>', 'part of a line'),
            $entry('header', 'Header', '<header>', '<header></header>', 'top'),
            $entry('footer', 'Footer', '<footer>', '<footer></footer>', 'bottom'),
            $entry('nav', 'Navigation', '<nav>', '<nav></nav>', 'menu links'),
            $entry('main', 'Main content', '<main>', '<main></main>'),
            $entry('article', 'Article', '<article>', '<article></article>', 'card post'),
            $entry('aside', 'Aside', '<aside>', '<aside></aside>', 'sidebar'),
            $entry('divider', 'Divider', '<hr>', '<hr>', 'line rule separator'),
            $entry('line-break', 'Line break', '<br>', '<br>', 'new line'),
            $entry('video', 'Video', '<video>', '<video src="" controls></video>', 'movie film'),
            $entry('label', 'Label', '<label>', '<label>Label</label>', 'form field'),
            $entry('input', 'Input', '<input>', '<input type="text" name="">', 'form field text'),
            $entry('textarea', 'Text area', '<textarea>', '<textarea name="" rows="4"></textarea>', 'form field long text'),
            $entry('select', 'Select', '<select>', "<select name=\"\">\n    <option value=\"\">Option</option>\n</select>", 'form field dropdown choice'),
        ];
    }

    /** @return list<array{id: string, group: string, title: string, hint: string, blade: string, keywords: string}> */
    public static function blade(): array
    {
        $entry = fn (string $id, string $title, string $hint, string $blade, string $keywords = '') => self::entry('blade.'.$id, self::BLADE, $title, $hint, $blade, $keywords);

        return [
            $entry('if', 'Condition', '@if', "@if (true)\n@endif", 'show hide when only'),
            $entry('if-else', 'Condition, with an otherwise', '@if … @else', "@if (true)\n@else\n@endif", 'show hide when either or'),
            $entry('foreach', 'Loop', '@foreach', "@foreach ([] as \$item)\n@endforeach", 'repeat each list every'),
            $entry('forelse', 'Loop, with what to show when there is nothing', '@forelse … @empty', "@forelse ([] as \$item)\n@empty\n@endforelse", 'repeat each list every none'),
            $entry('echo', 'Value', '{{ }}', "{{ 'Value' }}", 'print echo variable field'),
            $entry('label', 'Interface text', '{{ __() }}', "{{ __('Text') }}", 'translate language words'),
            $entry('auth', 'Only for people signed in', '@auth', "@auth\n@endauth", 'user member login'),
            $entry('guest', 'Only for people not signed in', '@guest', "@guest\n@endguest", 'visitor login'),
            $entry('section', 'Section of a layout', '@section', "@section('content')\n@endsection", 'fill extends'),
            $entry('yield', 'Place for a section', '@yield', "@yield('content')", 'layout slot'),
            $entry('push', 'Addition to a stack', '@push', "@push('scripts')\n@endpush", 'script style head'),
            $entry('stack', 'Place for a stack', '@stack', "@stack('scripts')", 'script style head'),
            $entry('comment', 'Note', '{{-- --}}', '{{-- Note --}}', 'comment remark'),
            $entry('php', 'PHP', '@php', "@php\n@endphp", 'code variable'),
        ];
    }

    /**
     * Sunrice's components, each with a collection, taxonomy or form of
     * this site: a component that names one the site doesn't have answers
     * the whole page with "not found".
     *
     * @param  list<array{handle: string, title: string, single: bool}>  $collections
     * @param  list<array{handle: string, title: string}>  $taxonomies
     * @param  list<array{handle: string, title: string}>  $forms
     * @return list<array{id: string, group: string, title: string, hint: string, blade: string, keywords: string}>
     */
    public static function sunrice(array $collections, array $taxonomies, array $forms): array
    {
        $entry = fn (string $id, string $title, string $hint, string $blade, string $keywords = '') => self::entry('sunrice.'.$id, self::SUNRICE, $title, $hint, $blade, $keywords);
        $entries = [];
        // A handle is written into the Blade as it is: only one that can't end the attribute it is in.
        $plain = fn (array $subject): bool => preg_match('/^[A-Za-z0-9_-]+$/', $subject['handle']) === 1;
        [$collections, $taxonomies, $forms] = [array_filter($collections, $plain), array_filter($taxonomies, $plain), array_filter($forms, $plain)];

        foreach ($collections as $collection) {
            // Each one is `$item`, not `$entry`: on an entry's page that is the page's own, and the loop would leave it the last of these.
            $title = $collection['single'] ? '<h3><a href="{{ $item->url }}">{{ $item->title }}</a></h3>' : '<h3>{{ $item->title }}</h3>';
            $entries[] = $entry('entries.'.$collection['handle'], 'Entries: '.$collection['title'], '<x-sunrice::entries>', <<<BLADE
                <x-sunrice::entries collection="{$collection['handle']}" :limit="6">
                    @foreach (\$component->entries as \$item)
                        <article>
                            {$title}
                        </article>
                    @endforeach
                </x-sunrice::entries>
                BLADE, 'list latest query collection '.$collection['handle']);
        }
        foreach ($taxonomies as $taxonomy) {
            $entries[] = $entry('terms.'.$taxonomy['handle'], 'Terms: '.$taxonomy['title'], '<x-sunrice::terms>', <<<BLADE
                <x-sunrice::terms taxonomy="{$taxonomy['handle']}">
                    <ul>
                        @foreach (\$component->terms as \$term)
                            <li><a href="{{ \$component->url(\$term) }}">{{ \$term->name }}</a></li>
                        @endforeach
                    </ul>
                </x-sunrice::terms>
                BLADE, 'categories tags taxonomy list '.$taxonomy['handle']);
        }
        foreach ($forms as $form) {
            $entries[] = $entry('form.'.$form['handle'], 'Form: '.$form['title'], '<x-sunrice::form>', self::form($form['handle']), 'contact fields submit '.$form['handle']);
        }

        $entries[] = $entry('search', 'Search box', '<x-sunrice::search>', <<<'BLADE'
            <x-sunrice::search>
                <form method="get" action="{{ $component->action }}" role="search">
                    <input type="search" name="{{ $component->name }}" value="{{ $component->query }}" aria-label="{{ __('sunrice::frontend.search') }}">
                    <button type="submit">{{ __('sunrice::frontend.search') }}</button>
                </form>
            </x-sunrice::search>
            BLADE, 'find query');
        $entries[] = $entry('seo', 'SEO tags', '<x-sunrice::seo />', '<x-sunrice::seo />', 'title description meta head');
        foreach (['head' => 'in the head', 'body_start' => 'at the start of the body', 'body_end' => 'at the end of the body'] as $position => $where) {
            $entries[] = $entry('code.'.$position, 'Site code, '.$where, '<x-sunrice::code />', '<x-sunrice::code position="'.$position.'" />', 'analytics tracking scripts settings');
        }

        return $entries;
    }

    /**
     * The site's partials, as includes.
     *
     * @param  list<TemplateFile>  $files
     * @return list<array{id: string, group: string, title: string, hint: string, blade: string, keywords: string}>
     */
    public static function partials(array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            if ($file->origin !== TemplateFile::SITE || $file->role !== 'partial') {
                continue;
            }
            $source = @file_get_contents($file->file);
            $needs = array_map(fn (string $name) => '$'.$name, $source === false ? [] : Variables::needed($source));
            $entries[] = self::entry(
                'partial.'.$file->view,
                self::PARTIALS,
                substr($file->path, 0, -strlen('.blade.php')),
                $needs === [] ? '@include' : 'Needs '.implode(', ', $needs),
                "@include('{$file->view}')",
                'include template '.$file->view,
            );
        }

        return $entries;
    }

    /**
     * A form with an input for each of its fields, whatever they are when
     * the page is drawn: the markup of Sunrice's starter templates.
     */
    private static function form(string $handle): string
    {
        return <<<BLADE
            <x-sunrice::form handle="{$handle}">
                @foreach (\$component->form->fields as \$field)
                    @php(\$name = 'data['.\$field['handle'].']')
                    @php(\$old = \$component->old(\$field['handle']))
                    <label for="{$handle}-{{ \$field['handle'] }}">{{ (\$field['label'] ?? '') ?: \$field['handle'] }}</label>
                    @switch(\$field['type'])
                        @case('textarea')
                            <textarea id="{$handle}-{{ \$field['handle'] }}" name="{{ \$name }}" rows="5" @required(\$field['required'] ?? false)>{{ \$old }}</textarea>
                            @break
                        @case('select')
                            <select id="{$handle}-{{ \$field['handle'] }}" name="{{ \$name }}" @required(\$field['required'] ?? false)>
                                @foreach (\$field['config']['options'] ?? [] as \$option)
                                    @php([\$value, \$label] = is_array(\$option) ? [\$option['value'] ?? '', \$option['label'] ?? \$option['value'] ?? ''] : [\$option, \$option])
                                    <option value="{{ \$value }}" @selected((string) \$old === (string) \$value)>{{ \$label }}</option>
                                @endforeach
                            </select>
                            @break
                        @case('toggle')
                            <input type="hidden" name="{{ \$name }}" value="0">
                            <input id="{$handle}-{{ \$field['handle'] }}" type="checkbox" name="{{ \$name }}" value="1" @checked(\$old)>
                            @break
                        @case('file')
                            <input id="{$handle}-{{ \$field['handle'] }}" type="file" name="{{ \$name }}" @required(\$field['required'] ?? false)>
                            @break
                        @default
                            <input id="{$handle}-{{ \$field['handle'] }}" type="{{ ['number' => 'number', 'date' => 'date'][\$field['type']] ?? 'text' }}" name="{{ \$name }}" value="{{ \$old }}" @required(\$field['required'] ?? false)>
                    @endswitch
                    @if (\$error = \$component->error(\$field['handle']))
                        <p class="error">{{ \$error }}</p>
                    @endif
                @endforeach
            </x-sunrice::form>
            BLADE;
    }

    /** @return array{id: string, group: string, title: string, hint: string, blade: string, keywords: string} */
    private static function entry(string $id, string $group, string $title, string $hint, string $blade, string $keywords): array
    {
        return ['id' => $id, 'group' => $group, 'title' => $title, 'hint' => $hint, 'blade' => $blade, 'keywords' => $keywords];
    }
}
