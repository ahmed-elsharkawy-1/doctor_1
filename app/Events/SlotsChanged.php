<?php

namespace App\Events;

use App\Models\Clinic;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A clinic's day changed shape — somebody took a slot, gave one up, or
 * cancelled a visit.
 *
 * **It deliberately carries no slots.** The obvious payload would be "10:20
 * is now taken", but a slot is only meaningful next to a visit type: a
 * 20-minute كشف and a 45-minute استشارة see different grids over the same
 * hour, so one blocked time means different things to two people looking at
 * the same day. A client that applied our times to its own grid would be
 * wrong for every viewer whose visit type is not the one that changed.
 *
 * So the event says only *which day moved*, and each client re-asks for its
 * own availability. That is one small request per watching browser, of which
 * there are single digits at this size — and it is always right, which the
 * clever version would not be.
 *
 * It also means nothing about any patient rides on a public channel. The
 * channel is public because a stranger on the booking page has no account to
 * authenticate with; the only thing they learn from it is that a day they are
 * already looking at has changed.
 *
 * Queued, not sent inline. Broadcasting must never be able to fail a booking:
 * if Pusher is slow or down, the visit is still booked and the page falls
 * back to the state it reads on its next interaction.
 */
class SlotsChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $date  Y-m-d, in the clinic's own timezone
     */
    public function __construct(
        public readonly Clinic $clinic,
        public readonly string $date,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel(self::channelFor($this->clinic->id, $this->date));
    }

    /**
     * The one place the channel name is spelled, so the page and the event
     * cannot drift apart.
     */
    public static function channelFor(int $clinicId, string $date): string
    {
        return "slots.{$clinicId}.{$date}";
    }

    /**
     * The name the browser binds to.
     *
     * Without this Laravel broadcasts the fully-qualified class name, and the
     * page listening for `SlotsChanged` would wait for ever — the events would
     * reach Pusher and nothing would ever act on them. Kept in step by
     * BroadcastingTest, because a silent mismatch here looks exactly like a
     * working feature from the server's side.
     */
    public function broadcastAs(): string
    {
        return 'SlotsChanged';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['date' => $this->date];
    }
}
