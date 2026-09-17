<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Duty Defaults
    |--------------------------------------------------------------------------
    |
    | Fallback min/max invigilation duties per teacher for a session, used
    | when a session_teacher_constraints row doesn't set its own value.
    |
    */
    'default_min_duties' => 2,
    'default_max_duties' => 6,

    /*
    |--------------------------------------------------------------------------
    | Report Header Text
    |--------------------------------------------------------------------------
    |
    | Printed on generated seating charts, datesheets and duty sheets.
    |
    */
    'university_name' => env('EXAM_UNIVERSITY_NAME', 'The University of Lahore'),
    'department_name' => env('EXAM_DEPARTMENT_NAME', 'Department of Artificial Intelligence'),

    /*
    |--------------------------------------------------------------------------
    | Formatted Datesheet Template
    |--------------------------------------------------------------------------
    |
    | Printed as-is on every row of the Formatted Datesheet export (the
    | "Building/Block Name" and "Event Package (Abbrev./Description)"
    | columns) — the app has no per-subject building or event-package data
    | of its own, these are constant for the whole institution.
    |
    */
    'datesheet_building_block' => env('EXAM_DATESHEET_BUILDING', 'ITC'),
    'datesheet_event_package' => env('EXAM_DATESHEET_EVENT_PACKAGE', 'SE-LHR'),

];
