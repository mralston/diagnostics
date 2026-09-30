@props([
    // The run to show. Nothing is rendered when it is null or has no outcome yet.
    'run' => null,
    // The subject, to mark a run that is out of date. Optional.
    'subject' => null,
    // 'solid' (white symbol on a filled circle) or 'outline' (coloured symbol in a ring).
    'variant' => 'solid',
    'size' => 16,
])

@php
    $outcome = $run?->outcome?->value;

    $styles = [
        'passed' => ['colour' => '#1f8a5b', 'label' => 'passed'],
        'passed_with_warnings' => ['colour' => '#c27a0e', 'label' => 'passed with warnings'],
        'failed' => ['colour' => '#c4342d', 'label' => 'failed'],
        'errored' => ['colour' => '#6d3fb5', 'label' => 'could not finish'],
    ];

    $style = $styles[$outcome] ?? null;
    $stale = $style && $subject !== null && ! $run->isFreshFor($subject);
    $outline = $variant === 'outline';
    $ink = $outline ? $style['colour'] ?? '#000' : '#fff';

    $title = $style
        ? sprintf('%s %s%s%s',
            $run->suiteDefinition()->getLabel(),
            $style['label'],
            $run->finished_at ? ' on '.$run->finished_at->format('d/m/Y H:i') : '',
            $stale ? '. The record has changed since.' : '')
        : null;
@endphp

@if($style)
    <span {{ $attributes->merge([
              'class' => 'dx-outcome dx-outcome--'.$outcome.($stale ? ' dx-outcome--stale' : ''),
              'style' => 'display: inline-flex; vertical-align: -0.15em; line-height: 0;'.($stale ? ' opacity: 0.45;' : ''),
              'title' => $title,
          ]) }}
          role="img" aria-label="{{ $title }}">
        <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
            @if($outline)
                <circle cx="8" cy="8" r="7" fill="none" stroke="{{ $style['colour'] }}" stroke-width="1.5" />
            @else
                <circle cx="8" cy="8" r="8" fill="{{ $style['colour'] }}" />
            @endif

            @switch($outcome)
                @case('passed')
                    <path d="M4.6 8.3l2.3 2.3 4.5-4.8" fill="none" stroke="{{ $ink }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                    @break
                @case('passed_with_warnings')
                    <path d="M8 4.3v4.4" fill="none" stroke="{{ $ink }}" stroke-width="1.9" stroke-linecap="round" />
                    <circle cx="8" cy="11.4" r="1.05" fill="{{ $ink }}" />
                    @break
                @case('failed')
                    <path d="M5.4 5.4l5.2 5.2M10.6 5.4l-5.2 5.2" fill="none" stroke="{{ $ink }}" stroke-width="1.8" stroke-linecap="round" />
                    @break
                @case('errored')
                    <path d="M8.9 3.6L5.6 8.6h2.6l-1 3.8 3.3-5h-2.6l1-3.8z" fill="{{ $ink }}" />
                    @break
            @endswitch
        </svg>
    </span>
@endif
