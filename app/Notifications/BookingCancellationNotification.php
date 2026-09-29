<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCancellationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public string $type,
        public string $title,
        public string $body
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => $this->type,
            'title'      => $this->title,
            'body'       => $this->body,
            'booking_id' => $this->booking->id,
            'status'     => $this->booking->status,
            'created_at' => now()->toDateTimeString(),
        ];
    }
}