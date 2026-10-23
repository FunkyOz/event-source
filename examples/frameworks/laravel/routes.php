<?php

declare(strict_types=1);

/*
 * Streams the notifications of the logged-in user. To copy into routes/web.php of a Laravel application: it doesn't
 * run on its own. The package's service provider is registered automatically, and adds response()->sse().
 */

use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/notifications/stream', function (Request $request) {
    $userId = $request->user()->id;

    $stream = EventStream::create()
        ->every(2)
        ->heartbeat(15)
        ->maxDuration(300)
        ->onStart(function (Stream $stream) use ($userId): void {
            // First connection: start from the latest notification instead of sending the whole history
            if (null === $stream->lastEventId()) {
                $latest = DB::table('notifications')->where('user_id', $userId)->max('id') ?? 0;
                $stream->send(new Event('ready', id: (string) $latest));
            }
        })
        ->onTick(function (Stream $stream) use ($userId): void {
            $notifications = DB::table('notifications')
                ->where('user_id', $userId)
                ->where('id', '>', (int) $stream->lastEventId())
                ->orderBy('id')
                ->limit(50)
                ->get(['id', 'title']);

            foreach ($notifications as $notification) {
                $stream->send(Event::json('notification', $notification, id: (string) $notification->id));
            }
        });

    // Reads Last-Event-ID from the request, after a reconnection
    return response()->sse($stream);
})->middleware('auth');
