<?php
namespace App\Http;
class Kernel extends \Illuminate\Foundation\Http\Kernel {
protected $middleware=[\Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,\Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,\Illuminate\Foundation\Http\Middleware\TrimStrings::class];
protected $middlewareGroups=['web'=>[\Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,\Illuminate\Session\Middleware\StartSession::class,\Illuminate\View\Middleware\ShareErrorsFromSession::class,\App\Http\Middleware\VerifyCsrfToken::class,\Illuminate\Routing\Middleware\SubstituteBindings::class]];
}
