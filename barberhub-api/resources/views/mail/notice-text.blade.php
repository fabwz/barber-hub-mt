{!! $greeting !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@if ($actionUrl !== null)
{!! $actionLabel !!}: {!! $actionUrl !!}

@endif
{!! $footer !!}
