<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Sunrice\Facades\Sunrice;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Guard\CodeGuard;
use Sunrice\VisualEditor\Guard\Compiled;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/*
 * The editor's three permissions, and the line between the last two:
 * someone who may edit but not edit code can't make a template run PHP it
 * did not run before, by any operation, whatever they send.
 */

uses(WithStarterSite::class, WithEveryField::class);

const SVE_HERO = 'blocks/hero.blade.php';
const SVE_HERO_API = '/cms/visual-editor/api/templates/'.SVE_HERO;

/** What would run on the server if it got into a template as code. */
const SVE_CODE = [
    'an echo' => '{{ phpinfo() }}',
    'an echo of a secret' => '{{ config(\'app.key\') }}',
    'a raw echo' => '{!! system(\'id\') !!}',
    'a short @php' => '@php(system(\'id\'))',
    'a @php block' => "@php system('id'); @endphp",
    'PHP tags' => "<?php system('id'); ?>",
    'a PHP echo tag' => "<?= system('id') ?>",
    'an include of a view that is not the site\'s' => "@include('sunrice::admin.layout')",
    'a condition' => "@if (system('id')) x @endif",
    'a loop' => "@foreach (glob('/etc/*') as \$found) {{ \$found }} @endforeach",
    'a component given code' => '<x-sunrice::entries collection="articles" :where="system(\'id\')" />',
    'a directive that prints' => '@json($_SERVER)',
    'a call on a value that is there' => '{{ $block->delete() }}',
    'code in an attribute' => '<a href="{{ phpinfo() }}">x</a>',
    'code in a tag' => '<a @if (system(\'id\')) x @endif>y</a>',
];

beforeEach(function () {
    $this->setUpStarterSite();
    $this->setUpEveryField();
    File::deleteDirectory(storage_path('app/sunrice/template-backups'));
    $this->hero = $this->templates.'/'.SVE_HERO;
    $this->original = File::get($this->hero);

    /** Signs in someone who may use the admin and has these of the editor's permissions. */
    $this->as = function (string ...$permissions) {
        $user = createUser(email: implode('-', array_map(fn (string $name) => substr($name, 14), $permissions) ?: ['nobody']).'@example.com');
        foreach (['sunrice.access-admin', ...$permissions] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, config('sunrice.auth.guard', 'web')));
        }
        actingAs($user, config('sunrice.auth.guard', 'web'));

        return $user;
    };
    /** The id of the first node of the hero whose own text has $marker in it. */
    $this->node = function (string $marker, ?string $source = null): string {
        foreach (Template::open($source ?? File::get($this->hero))->root->walk() as $node) {
            if (str_contains($node->open, $marker)) {
                return $node->id();
            }
        }
        throw new RuntimeException("No node has \"{$marker}\".");
    };
    /** Sends operations for the hero as it is now. */
    $this->send = fn (array ...$operations) => postJson(SVE_HERO_API.'/operations', ['version' => sha1(File::get($this->hero)), 'operations' => $operations]);
    /** What the hero runs now that it did not run to begin with: the PHP in what Blade compiles it to. */
    $this->runsNew = fn (): array => array_keys(array_diff_key(Compiled::islands(File::get($this->hero)), Compiled::islands($this->original)));
    /** Everything the picker lists at a node of the hero, flat. */
    $this->picked = function (string $node, bool $inside = false): array {
        $values = [];
        $walk = function (array $all) use (&$walk, &$values): void {
            foreach ($all as $value) {
                $values[] = $value;
                foreach ($value['groups'] as $group) {
                    $walk($group['fields']);
                }
            }
        };
        $walk(getJson(SVE_HERO_API.'/fields?'.http_build_query(['version' => sha1(File::get($this->hero)), 'node' => $node] + ($inside ? ['inside' => 1] : [])))->assertOk()->json('variables'));

        return $values;
    };
});

afterEach(fn () => File::deleteDirectory(storage_path('app/sunrice/template-backups')));

// --- Who may what --------------------------------------------------------------

it('registers its permissions with Sunrice, for roles to be given', function () {
    expect(collect(Sunrice::permissions())->where('group', 'Visual editor')->pluck('label', 'name')->all())->toBe([
        'visual-editor.open' => 'Open the visual editor',
        'visual-editor.edit' => 'Change templates and styles in the visual editor',
        'visual-editor.code' => 'Edit template code in the visual editor',
    ]);
});

it('counts each permission as having the ones below it', function (array $has, array $may) {
    $user = ($this->as)(...$has);

    expect([Access::may($user, Access::OPEN), Access::may($user, Access::EDIT), Access::may($user, Access::CODE)])->toBe($may)
        ->and(Access::allowed($user))->toBe($may[0]);
})->with([
    'none' => [[], [false, false, false]],
    'open' => [[Access::OPEN], [true, false, false]],
    'edit' => [[Access::EDIT], [true, true, false]],
    'code' => [[Access::CODE], [true, true, true]],
    'open and edit' => [[Access::OPEN, Access::EDIT], [true, true, false]],
]);

it('gives a Super Admin all three, and nobody any while the editor is off', function () {
    $admin = actingAsSuperAdmin();
    expect(Access::may($admin, Access::CODE))->toBeTrue();

    config(['sunrice-visual-editor.enabled' => false]);
    expect(Access::may($admin, Access::OPEN))->toBeFalse()
        ->and(Access::may(null, Access::OPEN))->toBeFalse();
});

it('tells the screen what whoever opened it may do', function (array $has, ?array $can) {
    ($this->as)(...$has);

    $can === null
        ? get('/cms/visual-editor')->assertForbidden()
        : get('/cms/visual-editor')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('can', $can));
})->with([
    'none' => [[], null],
    'open' => [[Access::OPEN], ['edit' => false, 'code' => false]],
    'edit' => [[Access::EDIT], ['edit' => true, 'code' => false]],
    'code' => [[Access::CODE], ['edit' => true, 'code' => true]],
]);

it('lets someone who may only open the editor read everything and change nothing', function () {
    ($this->as)(Access::OPEN);
    $version = sha1($this->original);

    foreach (['templates', 'templates/'.SVE_HERO, 'templates/'.SVE_HERO.'/fields?version='.$version, 'inserts', 'site', 'styles?class=card'] as $read) {
        getJson('/cms/visual-editor/api/'.$read)->assertOk();
    }

    $css = File::get($this->theme.'/app.css');
    $layout = File::get($this->templates.'/layouts/app.blade.php');
    foreach ([
        ['POST', 'templates/'.SVE_HERO.'/operations', ['version' => $version, 'operations' => [['type' => 'addClass', 'node' => ($this->node)('<h1>'), 'class' => 'x']]]],
        ['PUT', 'templates/'.SVE_HERO.'/source', ['version' => $version, 'source' => '<p>x</p>']],
        ['POST', 'templates/defaults/term.blade.php/copy', []],
        ['POST', 'styles/files/app.css/changes', ['version' => sha1($css), 'changes' => [['type' => 'rule', 'selector' => '.x']]]],
        ['PUT', 'styles/files/app.css/source', ['version' => sha1($css), 'source' => '']],
        ['POST', 'styles/link', ['template' => 'layouts/app.blade.php', 'version' => sha1($layout), 'stylesheet' => 'app.css']],
    ] as [$method, $uri, $body]) {
        $this->json($method, '/cms/visual-editor/api/'.$uri, $body)->assertForbidden()->assertJsonPath('reason', 'forbidden');
    }

    expect(File::get($this->hero))->toBe($this->original)
        ->and(File::get($this->theme.'/app.css'))->toBe($css)
        ->and(File::get($this->templates.'/layouts/app.blade.php'))->toBe($layout);
});

it('lets nobody in who has none of the permissions', function () {
    ($this->as)();

    getJson('/cms/visual-editor/api/templates')->assertForbidden()->assertJsonPath('reason', 'forbidden');
    getJson('/cms/visual-editor/api/styles')->assertForbidden();
});

it('lets someone who may edit change stylesheets, link one and copy a view: none of it is code', function () {
    ($this->as)(Access::EDIT);
    $css = File::get($this->theme.'/app.css');
    File::delete($this->templates.'/taxonomies/show.blade.php');

    postJson('/cms/visual-editor/api/styles/files/app.css/changes', ['version' => sha1($css), 'changes' => [['type' => 'rule', 'selector' => '.x', 'declarations' => [['color', 'red']]]]])->assertOk();
    postJson('/cms/visual-editor/api/styles/files/editor.css/changes', ['version' => sha1(''), 'changes' => [['type' => 'rule', 'selector' => '.y']]])->assertOk();
    postJson('/cms/visual-editor/api/styles/link', ['template' => 'layouts/app.blade.php', 'version' => sha1(File::get($this->templates.'/layouts/app.blade.php')), 'stylesheet' => 'editor.css'])->assertOk();
    postJson('/cms/visual-editor/api/templates/defaults/term.blade.php/copy')->assertCreated();
});

// --- Code can't be got in: each operation ------------------------------------------

it('refuses code inserted into a template', function (string $type, string $code) {
    ($this->as)(Access::EDIT);

    ($this->send)(['type' => $type, 'node' => ($this->node)(in_array($type, ['append', 'prepend'], true) ? '<section' : '<h1>'), 'blade' => $code])
        ->assertStatus(422)->assertJsonPath('reason', 'rejected')->assertJsonPath('operation', null);

    expect(File::get($this->hero))->toBe($this->original);
})->with(['insertBefore', 'insertAfter', 'append', 'prepend'])->with(SVE_CODE);

it('writes what is typed as a text as text, which runs nothing', function (string $code) {
    ($this->as)(Access::EDIT);
    File::put($this->hero, $this->original = str_replace('<h1>{{ $block->heading }}</h1>', '<h1>Plain</h1>', $this->original));

    ($this->send)(['type' => 'setText', 'node' => ($this->node)('Plain'), 'text' => $code])->assertOk();

    expect(($this->runsNew)())->toBe([])
        ->and(File::get($this->hero))->not->toBe($this->original);
})->with(SVE_CODE);

it('writes an attribute given as text as text, and refuses one given as Blade unless it is a field', function (string $code) {
    ($this->as)(Access::EDIT);
    $h1 = ($this->node)('<h1>');

    ($this->send)(['type' => 'setAttribute', 'node' => $h1, 'name' => 'title', 'value' => $code, 'blade' => true])->assertStatus(422)->assertJsonPath('reason', 'rejected');
    expect(File::get($this->hero))->toBe($this->original);

    ($this->send)(['type' => 'setAttribute', 'node' => $h1, 'name' => 'title', 'value' => $code])->assertOk();
    expect(($this->runsNew)())->toBe([]);
})->with(SVE_CODE);

it('lets no code in through a name: of an attribute, a class, an id or a tag', function (array $operation, string $code) {
    ($this->as)(Access::EDIT);

    // Refused as no name at all, or written as text: either way nothing new runs.
    ($this->send)(array_map(fn ($value) => $value === '{code}' ? $code : $value, $operation) + ['node' => ($this->node)('<h1>')]);

    expect(($this->runsNew)())->toBe([]);
})->with([
    'an attribute\'s name' => [['type' => 'setAttribute', 'name' => '{code}', 'value' => 'x']],
    'a class' => [['type' => 'addClass', 'class' => '{code}']],
    'an id' => [['type' => 'setId', 'id' => '{code}']],
    'a tag' => [['type' => 'setTag', 'tag' => '{code}']],
])->with([...SVE_CODE, 'a name that ends the tag' => 'x"> {{ phpinfo() }} <i class="', 'a name with a directive' => 'x @php(system(\'id\')) y']);

it('refuses an expression, a condition, a loop and a node rewritten by hand', function (array $operation, string $at) {
    ($this->as)(Access::EDIT);

    ($this->send)($operation + ['node' => ($this->node)($at)])->assertStatus(422)->assertJsonPath('reason', 'rejected');

    expect(File::get($this->hero))->toBe($this->original);
})->with([
    'what an echo prints' => [['type' => 'setExpression', 'expression' => 'phpinfo()'], '{{ $block->heading }}'],
    'what an echo prints, a field and more' => [['type' => 'setExpression', 'expression' => '$block->heading.system(\'id\')'], '{{ $block->heading }}'],
    'a condition' => [['type' => 'setArguments', 'arguments' => "system('id')"], '@if ($block->subheading)'],
    'a condition, a field and more' => [['type' => 'setArguments', 'arguments' => "\$block->subheading && system('id')"], '@if ($block->subheading)'],
    'a condition compared with code' => [['type' => 'setArguments', 'arguments' => "\$block->subheading == system('id')"], '@if ($block->subheading)'],
    'a condition compared with a text that holds code' => [['type' => 'setArguments', 'arguments' => '$block->subheading == "{$block->delete()}"'], '@if ($block->subheading)'],
    'a condition compared with two things' => [['type' => 'setArguments', 'arguments' => "\$block->subheading == 'a'.phpinfo()"], '@if ($block->subheading)'],
    'the arguments of a @php' => [['type' => 'setArguments', 'arguments' => "system('id')"], '@php($image'],
    'a node as code' => [['type' => 'setCode', 'code' => '{{ phpinfo() }}'], '<h1>'],
    'a node as code, with PHP tags' => [['type' => 'setCode', 'code' => "<h1><?php system('id'); ?></h1>"], '<h1>'],
    'a wrapper with code' => [['type' => 'wrap', 'wrapper' => "@if (system('id'))\n@endif"], '<h1>'],
    'a wrapper with code in its tag' => [['type' => 'wrap', 'wrapper' => '<div title="{{ phpinfo() }}"></div>'], '<h1>'],
    'a wrapper that is a loop' => [['type' => 'wrap', 'wrapper' => "@foreach (glob('/etc/*') as \$found)\n@endforeach"], '<h1>'],
]);

it('refuses a loop over anything but a list that is there, under any name but a plain one', function (string $arguments) {
    ($this->as)(Access::EDIT);
    File::put($this->hero, $this->original = str_replace('<h1>', "@foreach ([] as \$item)\n    <i></i>\n    @endforeach\n    <h1>", $this->original));

    ($this->send)(['type' => 'setArguments', 'node' => ($this->node)('@foreach'), 'arguments' => $arguments])->assertStatus(422)->assertJsonPath('reason', 'rejected');

    expect(File::get($this->hero))->toBe($this->original);
})->with([
    "glob('/etc/*') as \$found",
    "sunrice_menu('main') ?? [] as \$item->x",
    "sunrice_menu('main') ?? [] as \$this",
    "sunrice_menu(system('id')) ?? [] as \$item",
    "sunrice_menu('main') ?? system('id') as \$item",
]);

it('refuses a whole text that runs anything new, and takes one that does not', function (string $code) {
    ($this->as)(Access::EDIT);

    putJson(SVE_HERO_API.'/source', ['version' => sha1($this->original), 'source' => str_replace('<h1>', $code.'<h1>', $this->original)])
        ->assertStatus(422)->assertJsonPath('reason', 'rejected');
    expect(File::get($this->hero))->toBe($this->original);
})->with(SVE_CODE);

it('lets someone who may not edit code rewrite what runs no code, and rearrange code that is there', function () {
    ($this->as)(Access::EDIT);
    // A paragraph added, the heading's echo moved into it, the image's condition gone: nothing new.
    $changed = str_replace(['<h1>{{ $block->heading }}</h1>', "    @if (\$image)\n", "    @endif\n    <h1>"], ['<h1>Hello</h1><p class="lead">{{ $block->heading }}</p>', '', '    <h1>'], $this->original);

    putJson(SVE_HERO_API.'/source', ['version' => sha1($this->original), 'source' => $changed])->assertOk();

    expect(File::get($this->hero))->toBe($changed)->not->toBe($this->original);
});

it('checks a whole text against the version it replaces, not against what the file was a moment before', function (bool $aimedAtWhatComes) {
    ($this->as)(Access::EDIT);
    // Someone who may edit code takes the code out of the template while the other's text is being
    // checked: here, the first time the check compiles anything.
    $without = "<section class=\"block hero\">\n    <h1>Nothing runs here</h1>\n</section>\n";
    $landed = false;
    Blade::precompiler(function (string $blade) use (&$landed, $without): string {
        if (! $landed) {
            $landed = true;
            File::put($this->hero, $without);
        }

        return $blade;
    });
    // The other's text keeps the code the template had, and says it is for whichever version.
    $kept = str_replace('<h1>', '<p>Mine</p><h1>', $this->original);

    putJson(SVE_HERO_API.'/source', ['version' => sha1($aimedAtWhatComes ? $without : $this->original), 'source' => $kept])
        ->assertStatus(409)->assertJsonPath('reason', 'conflict');

    // Their text is not written either way: the code taken out can't come back with it. Aimed at the
    // version before, it meets the other's save; aimed at the one coming, it is turned away before
    // anything is compared, while the file is still the one with the code.
    expect(File::get($this->hero))->toBe($aimedAtWhatComes ? $this->original : $without);
})->with([
    'said to be for the version before' => [false],
    'said to be for the version that is coming' => [true],
])->after(fn () => File::put($this->hero, $this->original));

// --- What the editor offers is not held back ---------------------------------------

it('takes everything the Insert library offers', function () {
    ($this->as)(Access::EDIT);
    $inserts = getJson('/cms/visual-editor/api/inserts')->assertOk()->json('inserts');
    // All but the block of PHP, which is for those who may write PHP.
    expect(count($inserts))->toBeGreaterThan(20)
        ->and(array_column($inserts, 'id'))->not->toContain('blade.php')->toContain('blade.if', 'blade.foreach');

    foreach ($inserts as $insert) {
        File::put($this->hero, $this->original);
        $response = ($this->send)(['type' => 'insertAfter', 'node' => ($this->node)('<h1>'), 'blade' => $insert['blade']]);
        expect($response->status())->toBe(200, $insert['id'].': '.$response->json('message'));
    }
});

it('takes every field and value the picker lists, each way it can be inserted', function (string $marker, bool $inside) {
    ($this->as)(Access::EDIT);
    $node = ($this->node)($marker);
    $tried = 0;

    foreach (($this->picked)($node, $inside) as $value) {
        foreach ($value['inserts'] as $insert) {
            File::put($this->hero, $this->original);
            ($this->send)(['type' => $inside ? 'append' : 'insertAfter', 'node' => $node, 'blade' => $insert['blade']])->assertOk();
            $tried++;
        }
    }

    expect($tried)->toBeGreaterThan(40);
})->with([
    'after the heading' => ['<h1>', false],
    'inside the section' => ['<section', true],
]);

it('takes a field for what an echo prints, for an attribute and for a text, and prints it either way', function () {
    ($this->as)(Access::EDIT);
    $echo = ($this->node)('{{ $block->heading }}');
    $printable = array_values(array_filter(($this->picked)($echo), fn (array $value) => $value['expression'] !== null));
    expect(count($printable))->toBeGreaterThan(20);

    foreach ($printable as $value) {
        File::put($this->hero, $this->original);
        ($this->send)(['type' => 'setExpression', 'node' => $echo, 'expression' => $value['expression']], ['type' => 'setEchoRaw', 'node' => $echo, 'raw' => str_starts_with($value['print'], '{!!')])->assertOk();

        File::put($this->hero, $this->original);
        ($this->send)(['type' => 'setAttribute', 'node' => ($this->node)('<h1>'), 'name' => 'title', 'value' => '{{ '.$value['expression'].' }}', 'blade' => true])->assertOk();
    }

    File::put($this->hero, $this->original);
    ($this->send)(['type' => 'setEchoRaw', 'node' => $echo, 'raw' => true])->assertOk();
    expect(File::get($this->hero))->toContain('<h1>{!! $block->heading !!}</h1>');
});

it('takes a condition built from choices about any value at the place', function () {
    ($this->as)(Access::EDIT);
    $head = ($this->node)('@if ($block->subheading)');
    $values = array_values(array_filter(($this->picked)($head), fn (array $value) => is_string($value['asked'] ?? null)));
    expect(count($values))->toBeGreaterThan(20);
    $not = fn (string $code) => str_starts_with($code, '! ') ? substr($code, 2) : (preg_match('/^\S+$/', $code) === 1 ? '! '.$code : '! ('.$code.')');

    foreach ($values as $value) {
        // As condition() in lib/builders.ts writes each test.
        foreach ([
            $value['asked'],
            $not($value['asked']),
            $value['compared']." == 'it\\'s; } ) (x'",
            $value['compared'].' != 10',
            $value['compared'].' > -2.5',
            $value['compared'].' < true',
            'str_contains((string) '.$value['compared'].", 'a')",
            "in_array('a', (array) ".$value['compared'].')',
            $value['compared'].'?->isPast()',
            $value['compared'].'?->isFuture()',
        ] as $condition) {
            File::put($this->hero, $this->original);
            $response = ($this->send)(['type' => 'setArguments', 'node' => $head, 'arguments' => $condition]);
            expect($response->status())->toBe(200, $condition.': '.$response->json('message'));
        }
    }
});

it('refuses a condition compared with a text Blade would make something of', function (string $text) {
    ($this->as)(Access::EDIT);
    // A text in quotes is no code to PHP. Blade reads the template first, and takes these for its own wherever they stand.
    ($this->send)(['type' => 'setArguments', 'node' => ($this->node)('@if ($block->subheading)'), 'arguments' => "\$block->subheading == '".$text."'"]);

    expect(($this->runsNew)())->toBe([])
        ->and(File::get($this->hero))->toBe($this->original);
})->with([
    'PHP tags' => ['<?php system("id"); ?>'],
    'a PHP echo tag' => ['<?= system("id") ?>'],
    'the start of a @php block' => ['@php system("id"); __halt_compiler(); '],
    'an echo' => ['{{ system("id") }}'],
    'a raw echo' => ['{!! system("id") !!}'],
    'a directive' => ['@json($_SERVER)'],
]);

it('takes a text written out for what an echo prints, and an interface text', function () {
    ($this->as)(Access::EDIT);
    $echo = ($this->node)('{{ $block->heading }}');

    foreach (["'Hello'", '42', "__('sunrice::frontend.learn_more')", "__('Read more')"] as $expression) {
        File::put($this->hero, $this->original);
        ($this->send)(['type' => 'setExpression', 'node' => $echo, 'expression' => $expression])->assertOk();
    }
    foreach (["__(system('id'))", "__('a', [system('id')])", "'a'.phpinfo()", '"a{$block->delete()}"', "__('<?php system(1); ?>')"] as $expression) {
        File::put($this->hero, $this->original);
        ($this->send)(['type' => 'setExpression', 'node' => $echo, 'expression' => $expression]);
        expect(File::get($this->hero))->toBe($this->original, $expression);
    }
});

it('takes a loop over any list at the place, each item under a plain name', function () {
    ($this->as)(Access::EDIT);
    File::put($this->hero, $this->original = str_replace('<h1>', "@foreach ([] as \$item)\n    <i></i>\n    @endforeach\n    <h1>", $this->original));
    $head = ($this->node)('@foreach');
    $lists = array_values(array_filter(($this->picked)($head), fn (array $value) => $value['looped'] !== null));
    expect($lists)->not->toBeEmpty();

    foreach ($lists as $value) {
        foreach ([' as $item', ' as $index => $each'] as $names) {
            File::put($this->hero, $this->original);
            ($this->send)(['type' => 'setArguments', 'node' => $head, 'arguments' => $value['looped'].$names])->assertOk();
        }
    }
});

it('takes the wrappers the Settings panel offers, which are the ones it lists', function () {
    ($this->as)(Access::EDIT);
    preg_match_all("/wrapper: '((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/lib/move.ts'), $listed);
    expect(array_map(stripcslashes(...), $listed[1]))->toBe(CodeGuard::WRAPPERS);

    foreach (CodeGuard::WRAPPERS as $wrapper) {
        File::put($this->hero, $this->original);
        ($this->send)(['type' => 'wrap', 'node' => ($this->node)('<h1>'), 'wrapper' => $wrapper])->assertOk();
    }
});

it('takes plain values for what a component is given, and refuses code there', function () {
    ($this->as)(Access::EDIT);
    $entries = collect(getJson('/cms/visual-editor/api/inserts')->json('inserts'))->first(fn (array $insert) => str_contains($insert['blade'], '<x-sunrice::entries'));
    ($this->send)(['type' => 'insertAfter', 'node' => ($this->node)('<h1>'), 'blade' => $entries['blade']])->assertOk();
    $this->original = File::get($this->hero);
    $tag = fn () => ($this->node)('<x-sunrice::entries');

    // As the Entries controls send them: several at once for one tag.
    ($this->send)(
        ['type' => 'setAttribute', 'node' => $tag(), 'name' => 'collection', 'value' => 'things', 'blade' => false],
        ['type' => 'setAttribute', 'node' => $tag(), 'name' => ':limit', 'value' => '3', 'blade' => true],
        ['type' => 'setAttribute', 'node' => $tag(), 'name' => 'order-by', 'value' => '-published_at', 'blade' => false],
        ['type' => 'setAttribute', 'node' => $tag(), 'name' => ':where', 'value' => "[['price', '>', 10], ['title', '!=', 'it\\'s']]", 'blade' => true],
    )->assertOk();
    ($this->send)(['type' => 'removeAttribute', 'node' => $tag(), 'name' => ':limit'], ['type' => 'setAttribute', 'node' => $tag(), 'name' => ':paginate', 'value' => 'true', 'blade' => true])->assertOk();
    expect(File::get($this->hero))->toContain(':paginate="true"')->toContain(":where=\"[['price', '>', 10], ['title', '!=', 'it\\'s']]\"")->not->toContain(':limit');

    $now = File::get($this->hero);
    foreach ([
        [':where', "system('id')", true],
        [':where', "[['price', '>', system('id')]]", true],
        [':where', '[["price", ">", "{$x}"]]', true],
        [':where', "[['price', '>', '<?php system(1); ?>']]", true],
        [':where', "[['price', '>', '@php system(1); __halt_compiler();']]", true],
        [':limit', 'phpinfo()', true],
        [':limit', '$_GET[1]', true],
        ['collection', '{{ phpinfo() }}', true],
        ['collection', "x\" :where=\"system('id')", true],
        ['x-on:y', '@php(system(\'id\'))', true],
    ] as [$name, $value, $blade]) {
        ($this->send)(['type' => 'setAttribute', 'node' => $tag(), 'name' => $name, 'value' => $value, 'blade' => $blade]);

        expect(array_keys(array_diff_key(Compiled::islands(File::get($this->hero)), Compiled::islands($now))))->toBe([], "{$name}=\"{$value}\"");
        File::put($this->hero, $now);
    }
});

// --- Undo and redo --------------------------------------------------------------------

it('lets a change be undone and done again by writing the template as it was', function () {
    ($this->as)(Access::EDIT);
    $field = collect(($this->picked)(($this->node)('<h1>')))->first(fn (array $value) => $value['print'] !== null && str_contains($value['code'], '$sunricePage'));

    ($this->send)(['type' => 'insertAfter', 'node' => ($this->node)('<h1>'), 'blade' => $field['print']])->assertOk();
    $changed = File::get($this->hero);
    expect(($this->runsNew)())->not->toBe([]);

    // Undo: the file as it was, which runs less. Redo: the file as it was after, which is one it has been.
    putJson(SVE_HERO_API.'/source', ['version' => sha1($changed), 'source' => $this->original])->assertOk();
    putJson(SVE_HERO_API.'/source', ['version' => sha1($this->original), 'source' => $changed])->assertOk();
    expect(File::get($this->hero))->toBe($changed);

    // Not a text that only looks like one it has been.
    putJson(SVE_HERO_API.'/source', ['version' => sha1($changed), 'source' => $changed.'{{ phpinfo() }}'])->assertStatus(422);
    expect(File::get($this->hero))->toBe($changed);
});

// --- Those who may edit code ----------------------------------------------------------

it('lets someone who may edit code write any', function (string $code) {
    ($this->as)(Access::CODE);

    $status = ($this->send)(['type' => 'insertAfter', 'node' => ($this->node)('<h1>'), 'blade' => $code])->status();
    // Held to what holds for everyone, the syntax check and the tree, and to nothing more.
    expect($status === 200 ? ($this->runsNew)() : ['refused'])->not->toBe([]);

    putJson(SVE_HERO_API.'/source', ['version' => sha1(File::get($this->hero)), 'source' => $this->original."\n{{ phpinfo() }}\n"])->assertOk();
})->with([SVE_CODE['an echo'], SVE_CODE['a short @php'], SVE_CODE['a condition'], SVE_CODE['a component given code']]);
