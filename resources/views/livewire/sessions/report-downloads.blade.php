<div class="space-y-4">
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-xl ring-1 ring-gray-200 dark:ring-gray-700/60 p-4">
            <div class="flex items-center gap-2 mb-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300">
                    <x-icon name="grid" class="h-4 w-4" />
                </span>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Room-wise Seating Chart</h4>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">One sitting plan per room, per slot &mdash; roll numbers filled column by column, matching the department's usual layout.</p>
            @if ($hasSeating)
                <div class="flex items-center gap-2">
                    <a href="{{ route('sessions.reports.seating-chart.xlsx', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">Excel</x-btn>
                    </a>
                    <a href="{{ route('sessions.reports.seating-chart.pdf', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">PDF</x-btn>
                    </a>
                </div>
            @else
                <p class="text-xs text-gray-400 italic">Generate seating first.</p>
            @endif
        </div>

        <div class="rounded-xl ring-1 ring-gray-200 dark:ring-gray-700/60 p-4">
            <div class="flex items-center gap-2 mb-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-300">
                    <x-icon name="calendar" class="h-4 w-4" />
                </span>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Master Datesheet</h4>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Every subject, section, date, time, room and invigilator for the whole session in one table, grouped by day.</p>
            @if ($hasSeating)
                <div class="flex items-center gap-2">
                    <a href="{{ route('sessions.reports.datesheet.xlsx', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">Excel</x-btn>
                    </a>
                    <a href="{{ route('sessions.reports.datesheet.pdf', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">PDF</x-btn>
                    </a>
                </div>
            @else
                <p class="text-xs text-gray-400 italic">Generate seating first.</p>
            @endif
        </div>

        <div class="rounded-xl ring-1 ring-gray-200 dark:ring-gray-700/60 p-4">
            <div class="flex items-center gap-2 mb-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-green-50 dark:bg-green-900/40 text-green-600 dark:text-green-300">
                    <x-icon name="clipboard" class="h-4 w-4" />
                </span>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Teacher Duty Roster</h4>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Every teacher's invigilation duties &mdash; date, time, room and subject &mdash; grouped by teacher.</p>
            @if ($hasDuties)
                <div class="flex items-center gap-2 flex-wrap">
                    <a href="{{ route('sessions.reports.duty-roster.xlsx', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">Excel</x-btn>
                    </a>
                    <a href="{{ route('sessions.reports.duty-roster.pdf', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">PDF</x-btn>
                    </a>
                    <x-btn wire:click="emailAllDutySheets" wire:confirm="Email each teacher their personal duty sheet as a PDF? This sends one email per teacher with duties this session." variant="secondary" size="sm" icon="mail">
                        <span wire:loading.remove wire:target="emailAllDutySheets">Email All</span>
                        <span wire:loading wire:target="emailAllDutySheets">Sending&hellip;</span>
                    </x-btn>
                </div>
            @else
                <p class="text-xs text-gray-400 italic">Generate duties first.</p>
            @endif
        </div>

        <div class="rounded-xl ring-1 ring-gray-200 dark:ring-gray-700/60 p-4">
            <div class="flex items-center gap-2 mb-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 dark:bg-amber-900/40 text-amber-600 dark:text-amber-300">
                    <x-icon name="document" class="h-4 w-4" />
                </span>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Subject-wise Seating List</h4>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Every seated student grouped by subject &mdash; roll no, name, section, room, seat and invigilator. Useful as an attendance sheet.</p>
            @if ($hasSeating)
                <div class="flex items-center gap-2">
                    <a href="{{ route('sessions.reports.subject-wise-seating.xlsx', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">Excel</x-btn>
                    </a>
                    <a href="{{ route('sessions.reports.subject-wise-seating.pdf', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">PDF</x-btn>
                    </a>
                </div>
            @else
                <p class="text-xs text-gray-400 italic">Generate seating first.</p>
            @endif
        </div>

        <div class="rounded-xl ring-1 ring-gray-200 dark:ring-gray-700/60 p-4">
            <div class="flex items-center gap-2 mb-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 dark:bg-rose-900/40 text-rose-600 dark:text-rose-300">
                    <x-icon name="user-group" class="h-4 w-4" />
                </span>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Batch / Section Schedule</h4>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">One class's full exam schedule at a time (e.g. BSAI 2A) &mdash; subject, date, time, room and invigilator, in order.</p>
            @if ($hasSeating)
                <div class="flex items-center gap-2">
                    <a href="{{ route('sessions.reports.batch-schedule.xlsx', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">Excel</x-btn>
                    </a>
                    <a href="{{ route('sessions.reports.batch-schedule.pdf', $examSession) }}">
                        <x-btn variant="secondary" size="sm" icon="download">PDF</x-btn>
                    </a>
                </div>
            @else
                <p class="text-xs text-gray-400 italic">Generate seating first.</p>
            @endif
        </div>
    </div>
</div>
