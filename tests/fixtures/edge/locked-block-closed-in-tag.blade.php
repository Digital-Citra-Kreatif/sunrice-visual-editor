{{-- The @if is in the tree and its @endif is inside a tag: the block has no end the tree can see. --}}
@if ($wide) <div class="wide" @endif>
    <p>Text</p>
</div>
