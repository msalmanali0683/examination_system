<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permission Catalog
    |--------------------------------------------------------------------------
    |
    | Every permission key the app checks against, with a human-readable
    | label used on the Users management screen.
    |
    */
    'catalog' => [
        'manage_users' => 'Manage users & permissions',
        'manage_rooms' => 'Manage rooms',
        'manage_teachers' => 'Manage teachers',
        'manage_subjects' => 'Manage subjects (including merging duplicates)',
        'manage_sessions' => 'Manage exam sessions (rooms, teacher constraints, time slots)',
        'manage_enrollments' => 'Import & manage student enrollments',
        'generate_roster' => 'Run timetable / seating / duty generation',
        'edit_assignments' => 'Manually edit seat & duty assignments (drag-drop review)',
        'finalize_sessions' => 'Finalize / unlock exam sessions',
        'view_reports' => 'View & export reports',
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Defaults
    |--------------------------------------------------------------------------
    |
    | The default permission set for each role. A user's effective
    | permission is this default, unless overridden per-user in the
    | user_permissions table (see User::hasPermission()).
    |
    */
    'defaults' => [
        'head' => [
            'manage_users' => true,
            'manage_rooms' => true,
            'manage_teachers' => true,
            'manage_subjects' => true,
            'manage_sessions' => true,
            'manage_enrollments' => true,
            'generate_roster' => true,
            'edit_assignments' => true,
            'finalize_sessions' => true,
            'view_reports' => true,
        ],
        'staff' => [
            'manage_users' => false,
            'manage_rooms' => true,
            'manage_teachers' => true,
            'manage_subjects' => true,
            'manage_sessions' => true,
            'manage_enrollments' => true,
            'generate_roster' => true,
            'edit_assignments' => true,
            'finalize_sessions' => false,
            'view_reports' => true,
        ],
    ],

];
