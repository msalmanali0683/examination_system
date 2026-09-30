@props(['examSession', 'reason'])

<div class="p-4 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200 rounded-lg text-sm space-y-2" role="alert">
    <p class="font-medium">{{ $reason }}, so the timetable, seating plan and duties no longer match.</p>
    <p>Regenerate them in this order:
        <a href="{{ route('sessions.timetable', $examSession) }}" wire:navigate class="font-semibold underline">1. Timetable</a>
        &rarr;
        <a href="{{ route('sessions.seating', $examSession) }}" wire:navigate class="font-semibold underline">2. Seating plan</a>
        &rarr;
        <a href="{{ route('sessions.duties', $examSession) }}" wire:navigate class="font-semibold underline">3. Duties</a>
    </p>
</div>
