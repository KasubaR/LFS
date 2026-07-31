<?php

namespace App\Services;

use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Support\Uuid;
use InvalidArgumentException;

class ContactMessageService
{
    public const STATUS = ['New', 'Read', 'Responded'];

    private const MAX_REPLY_CHARS = 5000;

    /**
     * @param  array<string, string>  $data
     */
    public function create(array $data): string
    {
        $name = trim(($data['firstName'] ?? '').' '.($data['lastName'] ?? ''));
        $email = trim($data['email'] ?? '');
        $message = trim($data['message'] ?? '');

        if ($name === '' || $email === '' || $message === '') {
            throw new InvalidArgumentException('name, email, and message are required.');
        }

        $id = Uuid::v4();

        ContactMessage::query()->create([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'subject' => $data['subject'] ?? null,
            'message' => $message,
            'status' => self::STATUS[0],
        ]);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        return ContactMessage::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ContactMessage $message) => $this->toMessage($message))
            ->all();
    }

    public function getById(string $id): ?array
    {
        $message = ContactMessage::query()->find($id);

        return $message ? $this->toMessage($message) : null;
    }

    public function updateStatus(string $id, string $status): bool
    {
        if (! in_array($status, self::STATUS, true)) {
            throw new InvalidArgumentException(
                "Invalid status '{$status}'. Allowed: ".implode(', ', self::STATUS)
            );
        }

        return ContactMessage::query()
            ->whereKey($id)
            ->update(['status' => $status]) > 0;
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(self::STATUS, 0);

        $rows = ContactMessage::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->cnt;
        }

        return $counts;
    }

    public function delete(string $id): bool
    {
        return ContactMessage::query()->whereKey($id)->delete() > 0;
    }

    public function createReply(string $messageId, string $reply): string
    {
        $messageId = trim($messageId);
        $reply = trim($reply);

        if ($messageId === '' || $reply === '') {
            throw new InvalidArgumentException('messageId and reply are required.');
        }

        $reply = preg_replace("/\r\n?/", "\n", $reply) ?? $reply;
        $reply = strip_tags($reply);

        $replyLen = function_exists('mb_strlen')
            ? mb_strlen($reply, 'UTF-8')
            : strlen($reply);

        if ($replyLen > self::MAX_REPLY_CHARS) {
            throw new InvalidArgumentException(
                'Reply exceeds maximum length of '.self::MAX_REPLY_CHARS.' characters.'
            );
        }

        $id = Uuid::v4();

        ContactReply::query()->create([
            'id' => $id,
            'contact_message_id' => $messageId,
            'reply_message' => $reply,
        ]);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRepliesByMessageId(string $messageId): array
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return [];
        }

        return ContactReply::query()
            ->where('contact_message_id', $messageId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ContactReply $reply) => [
                'id' => $reply->id,
                'contact_message_id' => $reply->contact_message_id,
                'reply_message' => $reply->reply_message,
                'created_at' => (string) $reply->created_at,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toMessage(ContactMessage $message): array
    {
        return [
            'id' => $message->id,
            'name' => $message->name,
            'email' => $message->email,
            'subject' => $message->subject ?? '',
            'message' => $message->message,
            'status' => $message->status,
            'created_at' => (string) $message->created_at,
        ];
    }
}
