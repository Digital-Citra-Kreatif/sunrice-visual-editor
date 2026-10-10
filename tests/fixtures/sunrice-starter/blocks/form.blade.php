{{--
    Fieldset `form` — fields: `heading` (text), `form` (text: the handle of a
    form created under Forms, e.g. "contact").

    <x-sunrice::form> adds the action URL, CSRF token, spam honeypot, the
    submit button and the success message. You write the inputs; name them
    data[field_handle]. Inside it, $component->form->fields lists the form's
    fields, and $component->error('handle') / $component->old('handle') give
    this form's messages and previous input (other forms on the page keep theirs).
--}}
<section class="block" id="block-{{ $block->id }}">
    @if ($block->heading)
        <h2>{{ $block->heading }}</h2>
    @endif

    <x-sunrice::form :handle="$block->form">
        @foreach ($component->form->fields as $field)
            @php($name = 'data['.$field['handle'].']')
            @php($error = $component->error($field['handle']))
            @php($old = $component->old($field['handle']))
            <label for="f-{{ $field['handle'] }}">{{ ($field['label'] ?? '') ?: $field['handle'] }}</label>

            @switch($field['type'])
                @case('textarea')
                    <textarea id="f-{{ $field['handle'] }}" name="{{ $name }}" rows="5" @required($field['required'] ?? false)>{{ $old }}</textarea>
                    @break
                @case('select')
                    <select id="f-{{ $field['handle'] }}" name="{{ $name }}" @required($field['required'] ?? false)>
                        {{-- Options are stored as [{value, label}] or as a plain list of values. --}}
                        @foreach ($field['config']['options'] ?? [] as $option)
                            @php([$value, $label] = is_array($option) ? [$option['value'] ?? '', $option['label'] ?? $option['value'] ?? ''] : [$option, $option])
                            <option value="{{ $value }}" @selected((string) $old === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @break
                @case('toggle')
                    <input type="hidden" name="{{ $name }}" value="0">
                    <input id="f-{{ $field['handle'] }}" type="checkbox" name="{{ $name }}" value="1" @checked($old)>
                    @break
                @case('file')
                    <input id="f-{{ $field['handle'] }}" type="file" name="{{ $name }}" accept="{{ \Sunrice\Fields\Types\File::acceptAttribute($field) }}" @required($field['required'] ?? false)>
                    <small class="hint">{{ \Sunrice\Fields\Types\File::hint($field) }}</small>
                    @break
                @default
                    <input id="f-{{ $field['handle'] }}"
                           type="{{ ['number' => 'number', 'date' => 'date'][$field['type']] ?? (str_contains($field['handle'], 'email') ? 'email' : 'text') }}"
                           name="{{ $name }}" value="{{ $old }}" @required($field['required'] ?? false)>
            @endswitch

            @if ($error)
                <p class="error">{{ $error }}</p>
            @endif
        @endforeach
    </x-sunrice::form>
</section>
