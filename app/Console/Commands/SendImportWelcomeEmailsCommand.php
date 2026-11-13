<?php

namespace App\Console\Commands;

use App\Models\MembershipImportRecord;
use App\Notifications\WelcomeImportedMemberNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Sends the welcome email (temp password + expiry) to every member created by a
 * given import batch, for imports that ran without --send-welcome.
 */
class SendImportWelcomeEmailsCommand extends Command
{
    protected $signature = 'membership:send-welcome {batch : Import batch id} {--dry-run : List recipients without sending}';

    protected $description = 'Send welcome emails to the members created by an import batch';

    public function handle(): int
    {
        $tempPassword = (string) config('member_import.temp_password');
        if ($tempPassword === '') {
            $this->error('MEMBER_IMPORT_TEMP_PASSWORD is not configured.');

            return self::FAILURE;
        }

        $records = MembershipImportRecord::query()
            ->where('batch_id', $this->argument('batch'))
            ->with('user')
            ->get()
            ->filter(fn ($r) => $r->user !== null && $r->user->first_login === null);

        $this->info('Mailer: '.config('mail.default').' — recipients: '.$records->count());

        foreach ($records as $record) {
            $user = $record->user;
            if ($this->option('dry-run')) {
                $this->line($user->email);

                continue;
            }

            $expires = $user->temp_password_expires_at
                ? Carbon::parse($user->temp_password_expires_at)
                : now()->addDays((int) config('member_import.temp_password_ttl_days', 14));

            Notification::send($user, new WelcomeImportedMemberNotification($tempPassword, $expires));
        }

        return self::SUCCESS;
    }
}
