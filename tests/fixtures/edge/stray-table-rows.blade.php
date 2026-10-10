{{-- HTML lets <tr>, <th> and <td> go without their end tags. --}}
<table class="prices">
    <tr><th>Plan<th>Price
    @foreach ($plans as $plan)
        <tr><td>{{ $plan->name }}<td class="price">{{ $plan->price }}
    @endforeach
</table>
