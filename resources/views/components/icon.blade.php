@props(['name'])
@php
    $paths = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 6 2 8 2 8H4s2-2 2-8"/><path d="M10 21h4"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
        'store' => '<path d="M4 9l1.5-5h13L20 9"/><path d="M5 9v11h14V9"/><path d="M4 9a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0A2.7 2.7 0 0 0 20 9"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.2"/>',
        'layers' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
        'bank' => '<path d="M3 9l9-5 9 5"/><path d="M5 9v9M10 9v9M14 9v9M19 9v9"/><path d="M3 20h18"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.5 2.7-6 6-6s6 2.5 6 6"/><path d="M16 5a3.5 3.5 0 0 1 0 6.5M21 20c0-2.7-1.4-4.8-3.6-5.6"/>',
        'alert' => '<path d="M12 4l9 16H3z"/><path d="M12 10v5M12 17.5v.5"/>',
        'card' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/>',
        'logout' => '<path d="M10 4H5v16h5"/><path d="M15 8l4 4-4 4M19 12H9"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'download' => '<path d="M12 4v11M7 11l5 5 5-5"/><path d="M5 20h14"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ];
@endphp
<svg {{ $attributes->merge(['class' => 'icon']) }} viewBox="0 0 24 24" aria-hidden="true">{!! $paths[$name] !!}</svg>
