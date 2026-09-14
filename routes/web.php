<?php

use App\Livewire\Rooms\Index as RoomsIndex;
use App\Livewire\Teachers\Import as TeachersImport;
use App\Livewire\Teachers\Index as TeachersIndex;
use App\Livewire\Users\Index as UsersIndex;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('users', UsersIndex::class)
    ->middleware(['auth'])
    ->name('users.index');

Route::get('rooms', RoomsIndex::class)
    ->middleware(['auth'])
    ->name('rooms.index');

Route::get('teachers', TeachersIndex::class)
    ->middleware(['auth'])
    ->name('teachers.index');

Route::get('teachers/import', TeachersImport::class)
    ->middleware(['auth'])
    ->name('teachers.import');

require __DIR__.'/auth.php';
