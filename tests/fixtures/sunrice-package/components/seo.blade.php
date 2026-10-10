<title>{{ $documentTitle }}</title>
@if($description)
<meta name="description" content="{{ $description }}">
@endif
@if($robots)
<meta name="robots" content="{{ $robots }}">
@endif
@if($canonical)
<link rel="canonical" href="{{ $canonical }}">
@endif
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $title }}">
@if($description)
<meta property="og:description" content="{{ $description }}">
@endif
@if($canonical)
<meta property="og:url" content="{{ $canonical }}">
@endif
@if($publishedTime)
<meta property="article:published_time" content="{{ $publishedTime }}">
@endif
@if($modifiedTime)
<meta property="article:modified_time" content="{{ $modifiedTime }}">
@endif
<meta property="og:locale" content="{{ str_replace('-', '_', $locale) }}">
@foreach(array_keys($alternates) as $alternateLocale)
@if($alternateLocale !== 'x-default' && $alternateLocale !== $locale)
<meta property="og:locale:alternate" content="{{ str_replace('-', '_', $alternateLocale) }}">
@endif
@endforeach
@if($image)
<meta property="og:image" content="{{ $image }}">
@endif
<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
@if($twitterSite)
<meta name="twitter:site" content="{{ $twitterSite }}">
@endif
<meta name="twitter:title" content="{{ $title }}">
@if($description)
<meta name="twitter:description" content="{{ $description }}">
@endif
@if($image)
<meta name="twitter:image" content="{{ $image }}">
@endif
@foreach($alternates as $alternateLocale => $url)
<link rel="alternate" hreflang="{{ $alternateLocale }}" href="{{ $url }}">
@endforeach
