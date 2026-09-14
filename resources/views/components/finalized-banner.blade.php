@props(['session'])

@if ($session->isFinalized())
    <div class="p-4 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 rounded-lg text-sm flex items-center gap-2">
        <x-icon name="lock" class="h-4 w-4 shrink-0" />
        This session was finalized{{ $session->locked_at ? ' on '.$session->locked_at->format('d M Y, g:i A') : '' }} and is read-only. Unlock it from the session page to make changes.
    </div>
@endif
