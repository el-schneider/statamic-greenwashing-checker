{{-- Statamic 5 read-only utility: v5 has no Inertia, so this lists the entry
     summary index server-side. Scanning itself runs via greenwashing:scan or
     the shared JSON endpoints; no provider call happens on page load. --}}
@php
    $rows = collect();
    if (auth()->user()?->can('scan greenwashing findings')) {
        $scanner = app(\ElSchneider\GreenwashingChecker\Scanning\EntryScanner::class);
        $index = app(\ElSchneider\GreenwashingChecker\Storage\EntryIndex::class);
        $rows = $index->rows($scanner->entries([], user: auth()->user()), 1);
    }
@endphp

@extends('statamic::layout')
@section('title', __('greenwashing-checker::messages.title'))

@section('content')
<header class="mb-6">
    @include('statamic::partials.breadcrumb', ['url' => cp_route('utilities.index'), 'title' => __('Utilities')])
    <h1>{{ __('greenwashing-checker::messages.title') }}</h1>
</header>

<div class="card p-0">
    <div class="p-4">
        <h2 class="font-bold">{{ __('greenwashing-checker::scan.results') }}</h2>
        <p class="text-gray dark:text-dark-150 text-sm my-2">{{ __('greenwashing-checker::scan.description') }}</p>
    </div>

    @if ($rows->isEmpty())
        <p class="p-4 pt-0 text-sm">{{ __('greenwashing-checker::scan.empty') }}</p>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('greenwashing-checker::scan.entry') }}</th>
                    <th>{{ __('greenwashing-checker::scan.collection') }}</th>
                    <th>{{ __('greenwashing-checker::scan.site') }}</th>
                    <th>{{ __('greenwashing-checker::scan.open') }}</th>
                    <th>{{ __('greenwashing-checker::scan.high') }}</th>
                    <th>{{ __('greenwashing-checker::scan.ignored') }}</th>
                    <th>{{ __('greenwashing-checker::scan.approved') }}</th>
                    <th>{{ __('greenwashing-checker::scan.analysed_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <a href="{{ $row['edit_url'] }}">{{ $row['title'] }}</a>
                            @if ($row['stale'])
                                <span class="badge-pill">{{ __('greenwashing-checker::scan.stale') }}</span>
                            @endif
                        </td>
                        <td>{{ $row['collection_title'] }}</td>
                        <td>{{ $row['site_name'] }}</td>
                        <td>{{ $row['counts']['open'] }}</td>
                        <td>{{ $row['counts']['high'] }}</td>
                        <td>{{ $row['counts']['ignored'] }}</td>
                        <td>{{ $row['counts']['approved'] }}</td>
                        <td class="whitespace-nowrap">{{ $row['analysed_at'] === null ? '—' : \Carbon\Carbon::parse($row['analysed_at'])->locale(app()->getLocale())->isoFormat('lll') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<p class="mt-4 text-gray dark:text-dark-150 text-xs">{{ __('greenwashing-checker::messages.disclaimer') }}</p>
@endsection
