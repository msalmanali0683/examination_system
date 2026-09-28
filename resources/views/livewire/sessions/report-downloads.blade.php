<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    @foreach ($reportTypes as $report)
        <a href="{{ $report['available'] ? route('sessions.reports.show', [$examSession, $report['type']]) : '#' }}"
            @class([
                'rounded-xl ring-1 ring-gray-200 dark:ring-gray-700/60 p-4 block transition',
                'hover:ring-primary-300 dark:hover:ring-primary-700 hover:shadow-sm' => $report['available'],
                'opacity-60 cursor-not-allowed' => ! $report['available'],
            ])
            @unless ($report['available']) onclick="return false;" @endunless>
            <div class="flex items-center gap-2 mb-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 dark:bg-primary-900/40 text-primary-600 dark:text-primary-300">
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
