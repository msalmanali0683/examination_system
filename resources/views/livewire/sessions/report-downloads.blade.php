@php
    // Tailwind needs each full class string literally present somewhere
    // it scans — building "bg-{{ $color }}-50" by interpolation would
    // silently produce unstyled icons, since only whole class names get
    // included in the compiled CSS.
    $colorClasses = [
        'indigo' => 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300',
        'blue' => 'bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-300',
        'sky' => 'bg-sky-50 dark:bg-sky-900/40 text-sky-600 dark:text-sky-300',
        'teal' => 'bg-teal-50 dark:bg-teal-900/40 text-teal-600 dark:text-teal-300',
        'green' => 'bg-green-50 dark:bg-green-900/40 text-green-600 dark:text-green-300',
        'purple' => 'bg-purple-50 dark:bg-purple-900/40 text-purple-600 dark:text-purple-300',
        'amber' => 'bg-amber-50 dark:bg-amber-900/40 text-amber-600 dark:text-amber-300',
        'rose' => 'bg-rose-50 dark:bg-rose-900/40 text-rose-600 dark:text-rose-300',
    ];
@endphp

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    @foreach ($reportTypes as $report)
        <a href="{{ $report['available'] ? route('sessions.reports.show', [$examSession, $report['type']]) : '#' }}"
            @class([
                'rounded-xl ring-1 ring-gray-200 dark:ring-gray-700/60 p-4 block transition',
                'hover:ring-indigo-300 dark:hover:ring-indigo-700 hover:shadow-sm' => $report['available'],
                'opacity-60 cursor-not-allowed' => ! $report['available'],
            ])
            @unless ($report['available']) onclick="return false;" @endunless>
            <div class="flex items-center gap-2 mb-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $colorClasses[$report['color']] }}">
                    <x-icon :name="$report['icon']" class="h-4 w-4" />
                </span>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $report['label'] }}</h4>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $report['description'] }}</p>
            @unless ($report['available'])
                <p class="text-xs text-gray-400 italic mt-2">{{ \App\Services\Reports\ReportCatalog::gateHint($report['gate']) }}</p>
            @endunless
        </a>
    @endforeach
</div>
