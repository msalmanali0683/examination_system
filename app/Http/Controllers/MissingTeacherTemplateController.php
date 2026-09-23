<?php

namespace App\Http\Controllers;

use App\Models\ExamSession;
use App\Services\MissingTeacherSections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MissingTeacherTemplateController extends Controller
{
    /**
     * A CSV pre-filled with this session's own pending (un-taught)
     * subject/section pairs — Course Code, Course Title and Section
     * already in place, Teacher Name left blank for the admin to fill in
     * and re-upload via the Missing Teachers import. `?ignored=1` lists
     * the dismissed pairs instead, matching the import wizard's own
     * `ignored` scope so the two always agree on what's in the file.
     */
    public function download(Request $request, ExamSession $examSession): StreamedResponse
    {
        Gate::authorize('manage_sessions');

        $rows = $request->boolean('ignored')
            ? MissingTeacherSections::findIgnored($examSession)
            : MissingTeacherSections::find($examSession);

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Teacher Name', 'Course Code', 'Course Title', 'Section']);

            foreach ($rows as $row) {
                fputcsv($handle, ['', $row->code, $row->title, $row->section]);
            }

            fclose($handle);
        }, "missing-teachers-{$examSession->id}.csv", ['Content-Type' => 'text/csv']);
    }
}
