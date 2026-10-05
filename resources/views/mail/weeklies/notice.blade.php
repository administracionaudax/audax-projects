{{-- Avisos de la weekly (10.5): el cuerpo de la plantilla, un párrafo por bloque y sus saltos de línea, y un botón. --}}
<x-mail::message>
@foreach ($paragraphs as $lines)
{!! implode("  \n", array_map(fn (string $line): string => e($line), $lines)) !!}

@endforeach
<x-mail::button :url="$url">
{{ $action }}
</x-mail::button>
</x-mail::message>
