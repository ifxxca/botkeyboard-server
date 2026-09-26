<?php

use App\Http\Controllers\Admin\AttentionItemController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\LoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::redirect('/login', '/admin/login')->name('login');

Route::get('/admin/login', [LoginController::class, 'create'])->name('admin.login');
Route::post('/admin/login', [LoginController::class, 'store'])
    ->middleware('throttle:admin-login')->name('admin.login.store');

Route::prefix('admin')->middleware('auth')->name('admin.')->group(function (): void {
    Route::get('/', fn () => redirect()->route('admin.imports.index'))->name('home');
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('imports/{batch}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{batch}/commit', [ImportController::class, 'commit'])->name('imports.commit');
    Route::get('items', [AttentionItemController::class, 'index'])->name('items.index');
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('clients/create', [ClientController::class, 'create'])->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::post('clients/{client}/disable', [ClientController::class, 'disable'])->name('clients.disable');
    Route::post('clients/{client}/enable', [ClientController::class, 'enable'])->name('clients.enable');
    Route::post('clients/{client}/rotate', [ClientController::class, 'rotate'])->name('clients.rotate');
});
