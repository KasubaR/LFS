<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use App\Http\Requests\ReplyContactMessageRequest;
use App\Services\ContactMessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use Throwable;

class MessageController extends Controller
{
    /** @var list<string> */
    private const STATUSES = ['New', 'Read', 'Responded'];

    public function __construct(
        private readonly ContactMessageService $contactMessageService,
    ) {}

    public function index(): View
    {
        $messages = [];
        $statusCounts = ['New' => 0, 'Read' => 0, 'Responded' => 0];
        try {
            $messages = $this->contactMessageService->getAll();
            $statusCounts = $this->contactMessageService->countByStatus();
        } catch (Throwable) {
        }

        return view('admin.messages.index', [
            'pageTitle' => 'Contact Messages',
            'activePage' => 'messages',
            'messages' => $messages,
            'statusCounts' => $statusCounts,
            'flash' => session()->pull('admin_flash', []),
            'counts' => [
                'newMessages' => $statusCounts['New'] ?? 0,
                'pendingMembers' => 0,
                'pendingOrders' => 0,
                'pendingGallery' => 0,
            ],
        ]);
    }

    public function show(string $id): View
    {
        $message = $this->contactMessageService->getById($id);
        if ($message === null) {
            abort(404, 'Message not found.');
        }

        if ($message['status'] === 'New') {
            $this->contactMessageService->updateStatus($id, 'Read');
            $message['status'] = 'Read';
        }

        $statusCounts = $this->contactMessageService->countByStatus();
        try {
            $replies = $this->contactMessageService->getRepliesByMessageId($id);
        } catch (\Throwable) {
            $replies = [];
        }

        return view('admin.messages.show', [
            'pageTitle' => 'View Message',
            'activePage' => 'messages',
            'message' => $message,
            'replies' => $replies,
            'counts' => [
                'newMessages' => $statusCounts['New'] ?? 0,
                'pendingMembers' => 0,
                'pendingOrders' => 0,
                'pendingGallery' => 0,
            ],
        ]);
    }

    public function replyForm(string $id): View
    {
        $message = $this->contactMessageService->getById($id);
        if ($message === null) {
            abort(404, 'Message not found.');
        }

        $statusCounts = $this->contactMessageService->countByStatus();

        return view('admin.messages.reply', [
            'pageTitle' => 'Reply to Message',
            'activePage' => 'messages',
            'message' => $message,
            'flash' => [],
            'counts' => [
                'newMessages' => $statusCounts['New'] ?? 0,
                'pendingMembers' => 0,
                'pendingOrders' => 0,
                'pendingGallery' => 0,
            ],
        ]);
    }

    public function reply(ReplyContactMessageRequest $request, string $id): View|RedirectResponse
    {
        $message = $this->contactMessageService->getById($id);
        if ($message === null) {
            abort(404, 'Message not found.');
        }

        $replyText = $request->validated('reply_message');

        try {
            $this->contactMessageService->createReply($id, $replyText);
        } catch (\Throwable $e) {
            Log::error('[LFS Admin Messages] Reply save failed for message id='.$id.'; reason='.$e->getMessage());

            return redirect('/admin/messages')->with('admin_flash', [
                'error' => 'Reply could not be saved: '.$e->getMessage(),
            ]);
        }

        if (! $this->sendContactReplyEmail($message, $replyText)) {
            Log::error('[LFS Admin Messages] Reply mail delivery failed for message id='.$id);

            return redirect('/admin/messages')->with('admin_flash', [
                'warning' => 'Reply was saved, but email delivery failed. Status was not changed.',
            ]);
        }

        try {
            $this->contactMessageService->updateStatus($id, 'Responded');

            return redirect('/admin/messages')->with('admin_flash', [
                'success' => 'Reply sent and message marked as Responded.',
            ]);
        } catch (\Throwable $e) {
            Log::error('[LFS Admin Messages] Reply status update failed for message id='.$id.'; reason='.$e->getMessage());

            return redirect('/admin/messages')->with('admin_flash', [
                'warning' => 'Reply emailed successfully, but status update failed.',
            ]);
        }
    }

    public function updateStatus(string $id): RedirectResponse
    {
        $status = request()->input('status', '');
        if (! in_array($status, self::STATUSES, true)) {
            abort(422, 'Invalid status value.');
        }

        $this->contactMessageService->updateStatus($id, $status);

        return redirect('/admin/messages');
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->contactMessageService->delete($id);

        return redirect('/admin/messages');
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function sendContactReplyEmail(array $message, string $replyText): bool
    {
        $to = filter_var(trim((string) ($message['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        if ($to === false) {
            return false;
        }

        $name = trim((string) ($message['name'] ?? 'there'));
        $originalSubj = trim((string) ($message['subject'] ?? ''));
        $originalBody = trim((string) ($message['message'] ?? ''));
        $subject = 'Re: '.($originalSubj !== '' ? $originalSubj : 'Your message to Lusaka Fitness Squad');

        $body = "Hello {$name},\n\n";
        $body .= "Thank you for contacting Lusaka Fitness Squad.\n\n";
        $body .= "Admin reply:\n{$replyText}\n\n";
        $body .= "------------------------------\n";
        $body .= "Your original message:\n".($originalBody !== '' ? $originalBody : 'N/A')."\n\n";
        $body .= "Kind regards,\nLusaka Fitness Squad\n";

        try {
            Mail::raw($body, function ($mail) use ($to, $subject): void {
                $mail->to($to)->subject($subject)->from('noreply@lusakafitnesssquad.com', 'Lusaka Fitness Squad');
            });

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
