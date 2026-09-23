<x-slot name="header">
    <x-page-header :title="$config['label']" :subtitle="$examSession->name" :icon="$config['icon']" :back="route('sessions.show', ['examSession' => $examSession, 'tab' => 'reports'])" />
</x-slot>

<div class="space-y-4" @if ($status['state'] === 'in_progress') wire:poll.3s @endif>
    @if (session('status'))
        <div class="p-3 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="p-3 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 rounded-lg text-sm">
            {{ session('error') }}
        </div>
    @endif

    @php $reportQuery = array_merge(['examSession' => $examSession], $this->reportQuery()); @endphp

    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $config['description'] }}</p>

    @if (! $hasData)
        <x-empty-state :icon="$config['icon']" title="Nothing to report yet" :description="$config['gate'] === 'duties' ? 'Generate duties first.' : 'Generate seating first.'" />
    @else
        <x-card>
            <div class="flex items-center gap-6 flex-wrap">
                <div class="flex items-center gap-2">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Date</label>
                    <select wire:model.live="filterDate" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <option value="">All dates (whole exam)</option>
                        @foreach ($availableDates as $date)
                            <option value="{{ $date->toDateString() }}">{{ $date->format('d M Y (D)') }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($filterDate !== '' && $slotsForDate->isNotEmpty())
                    <div class="flex items-center gap-2 flex-wrap">
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Slots</label>
                        @foreach ($slotsForDate as $slot)
                            <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-900/40 rounded-lg px-2 py-1">
                                <input type="checkbox" wire:model.live="filterSlotIds" value="{{ $slot->id }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                {{ substr($slot->start_time, 0, 5) }}&ndash;{{ substr($slot->end_time, 0, 5) }}
                            </label>
                        @endforeach
                    </div>
                @endif

                @if ($config['flagLabel'])
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model.live="showFlag" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        {{ $config['flagLabel'] }}
                    </label>
                @endif
            </div>
            @if ($filterDate !== '' && $slotsForDate->isNotEmpty())
                <p class="text-xs text-gray-400 mt-2">Leave every slot unchecked to include the whole day, or check specific slots to narrow further &mdash; multiple slots can be selected at once.</p>
            @endif
        </x-card>

        <x-card>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('sessions.reports.'.$reportType.'.xlsx', $reportQuery) }}">
                    <x-btn variant="secondary" icon="download">Excel</x-btn>
                </a>
                @if (in_array('pdf', $config['formats']))
                    <a href="{{ route('sessions.reports.'.$reportType.'.pdf', $reportQuery) }}">
                        <x-btn variant="secondary" icon="download">PDF</x-btn>
                    </a>
                @endif
                @if ($reportType === 'duty-roster')
                    <x-btn wire:click="emailAllDutySheets" wire:confirm="Email each teacher their personal duty sheet as a PDF? This sends one email per teacher with duties this session." variant="secondary" icon="mail">
                        <span wire:loading.remove wire:target="emailAllDutySheets">Email All</span>
                        <span wire:loading wire:target="emailAllDutySheets">Sending&hellip;</span>
                    </x-btn>
                @endif
            </div>
            <x-report-status :status="$status" />
        </x-card>
    @endif
</div>
