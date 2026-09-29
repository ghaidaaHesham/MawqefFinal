<?php

namespace App\Notifications;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ComplaintNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Complaint $complaint,
        public string $type,       // 'new_complaint', 'admin_reply', 'status_updated'
        public string $title,
        public string $body
    ) {}

    /**
     * قنوات الإشعار (داخل التطبيق فقط)
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * البيانات المخزنة في جدول notifications
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type'         => $this->type,
            'title'        => $this->title,
            'body'         => $this->body,
            'complaint_id' => $this->complaint->id,
            'subject'      => $this->complaint->subject,
            'status'       => $this->complaint->status,
            'status_text'  => $this->complaint->status_text,
            'created_at'   => now()->toDateTimeString(),
        ];
    }
}