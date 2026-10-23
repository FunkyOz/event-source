<?php

declare(strict_types=1);

namespace App\Controller;

use App\Notification\NotificationRepository;
use FunkyOz\EventSource\Bridge\Symfony\StreamedResponseFactory;
use FunkyOz\EventSource\Event;
use FunkyOz\EventSource\EventStream;
use FunkyOz\EventSource\Stream;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Streams the notifications of the logged-in user. To copy into a Symfony application: it doesn't run on its own
 */
final readonly class NotificationController
{
    public function __construct(private NotificationRepository $notifications) {}

    #[Route('/notifications/stream', methods: ['GET'])]
    public function stream(Request $request): Response
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');
        // Release the session lock: otherwise the user's other requests wait until the stream ends
        $session->save();

        if (!is_int($userId)) {
            // EventSource doesn't reconnect after an error status
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $stream = EventStream::create()
            ->every(2)
            ->heartbeat(15)
            ->maxDuration(300)
            ->onStart(function (Stream $stream) use ($userId): void {
                // First connection: start from the latest notification instead of sending the whole history
                if (null === $stream->lastEventId()) {
                    $stream->send(new Event('ready', id: (string) $this->notifications->latestId($userId)));
                }
            })
            ->onTick(function (Stream $stream) use ($userId): void {
                foreach ($this->notifications->newerThan($userId, (int) $stream->lastEventId(), 50) as $notification) {
                    $stream->send(Event::json('notification', $notification, id: (string) $notification['id']));
                }
            });

        // The request gives the stream the Last-Event-ID header, after a reconnection
        return StreamedResponseFactory::create($stream, $request);
    }
}
