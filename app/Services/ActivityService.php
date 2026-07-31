<?php

namespace App\Services;

use Throwable;

class ActivityService
{
    public function __construct(
        private OrderService $orderService,
        private ContactMessageService $messageService,
        private BlogPostService $blogPostService,
        private EventService $eventService,
    ) {}

    /**
     * @return list<array{type: string, icon: string, title: string, subtitle: string, isoDate: string, timeAgo: string}>
     */
    public function getRecentActivity(int $limit = 20): array
    {
        $items = [];

        try {
            $orders = $this->orderService->getAll(['limit' => 10, 'offset' => 0]);
            foreach ($orders as $order) {
                $items[] = $this->normalizeOrder($order);
            }
        } catch (Throwable) {
        }

        try {
            $messages = array_slice($this->messageService->getAll(), 0, 10);
            foreach ($messages as $message) {
                $items[] = $this->normalizeMessage($message);
            }
        } catch (Throwable) {
        }

        try {
            $result = $this->blogPostService->getPosts(['limit' => 10]);
            foreach ($result['posts'] ?? [] as $post) {
                $items[] = $this->normalizeBlogPost($post);
            }
        } catch (Throwable) {
        }

        try {
            $events = $this->eventService->getRecentEvents(10);
            foreach ($events as $event) {
                $items[] = $this->normalizeEvent($event);
            }
        } catch (Throwable) {
        }

        usort($items, fn (array $a, array $b): int => strcmp($b['isoDate'], $a['isoDate']));

        return array_slice($items, 0, max(1, $limit));
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{type: string, icon: string, title: string, subtitle: string, isoDate: string, timeAgo: string}
     */
    private function normalizeOrder(array $order): array
    {
        $createdAt = $order['createdAt'] ?? '';
        $isoDate = $createdAt !== '' ? date('c', strtotime($createdAt)) : date('c');
        $total = number_format((float) ($order['total'] ?? 0), 2);

        return [
            'type' => 'order',
            'icon' => 'fas fa-bag-shopping',
            'title' => 'Order '.($order['orderNumber'] ?? ''),
            'subtitle' => ($order['customerName'] ?? '').' · ZMW '.$total,
            'isoDate' => $isoDate,
            'timeAgo' => $this->timeAgo($createdAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{type: string, icon: string, title: string, subtitle: string, isoDate: string, timeAgo: string}
     */
    private function normalizeMessage(array $message): array
    {
        $createdAt = $message['createdAt'] ?? '';
        $isoDate = $createdAt !== '' ? date('c', strtotime($createdAt)) : date('c');
        $name = trim(($message['name'] ?? '') ?: 'Unknown');
        $subtitle = $message['subject'] ?? $message['email'] ?? '';

        return [
            'type' => 'message',
            'icon' => 'fas fa-envelope',
            'title' => 'Message from '.$name,
            'subtitle' => $subtitle !== '' ? $subtitle : 'Contact form',
            'isoDate' => $isoDate,
            'timeAgo' => $this->timeAgo($createdAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array{type: string, icon: string, title: string, subtitle: string, isoDate: string, timeAgo: string}
     */
    private function normalizeBlogPost(array $post): array
    {
        $createdAt = $post['createdAt'] ?? '';
        $isoDate = $createdAt !== '' && $createdAt !== null
            ? date('c', is_numeric($createdAt) ? (int) $createdAt : strtotime($createdAt))
            : date('c');
        $title = $post['title'] ?? 'Post';
        $subtitle = $post['author'] ?? $post['category'] ?? '';

        return [
            'type' => 'blog',
            'icon' => 'fas fa-pencil',
            'title' => 'Post: '.$title,
            'subtitle' => $subtitle !== '' ? (string) $subtitle : 'Blog',
            'isoDate' => $isoDate,
            'timeAgo' => $this->timeAgo((string) $createdAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{type: string, icon: string, title: string, subtitle: string, isoDate: string, timeAgo: string}
     */
    private function normalizeEvent(array $event): array
    {
        $createdAt = $event['createdAt'] ?? '';
        $isoDate = $createdAt !== '' && $createdAt !== null
            ? date('c', is_numeric($createdAt) ? (int) $createdAt : strtotime($createdAt))
            : date('c');
        $title = $event['title'] ?? 'Event';
        $subtitle = $event['location'] ?? '';

        if ($subtitle === '' && ! empty($event['eventDate'])) {
            $subtitle = date('j M Y', strtotime($event['eventDate']));
        }

        return [
            'type' => 'event',
            'icon' => 'fas fa-calendar-days',
            'title' => 'Event: '.$title,
            'subtitle' => $subtitle,
            'isoDate' => $isoDate,
            'timeAgo' => $this->timeAgo((string) $createdAt),
        ];
    }

    private function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return '';
        }

        $diff = time() - $timestamp;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60).' min ago';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600).' hours ago';
        }
        if ($diff < 604800) {
            return (int) floor($diff / 86400).' days ago';
        }
        if ($diff < 2592000) {
            return (int) floor($diff / 604800).' weeks ago';
        }
        if ($diff < 31536000) {
            return (int) floor($diff / 2592000).' months ago';
        }

        return (int) floor($diff / 31536000).' years ago';
    }
}
