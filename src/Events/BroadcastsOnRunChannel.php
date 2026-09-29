<?php

namespace Mralston\Diagnostics\Events;

use Illuminate\Broadcasting\PrivateChannel;

trait BroadcastsOnRunChannel
{
    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('diagnostics.run.'.$this->run->id)];
    }

    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    public function broadcastWhen(): bool
    {
        return (bool) config('diagnostics.broadcast.enabled', true);
    }
}
