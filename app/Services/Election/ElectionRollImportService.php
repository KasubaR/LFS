<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionStatus;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionVoter;
use App\Models\ElectionVoterImportBatch;
use App\Models\Membership;
use App\Models\User;
use App\Support\MembershipNumberLookup;
use App\Support\Uuid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ElectionRollImportService
{
    public function __construct(
        private readonly ElectionAuditService $audit,
    ) {}

    /**
     * @return array{batch: ElectionVoterImportBatch, matched: int, unmatched: int, ambiguous: int}
     */
    public function import(Election $election, string|UploadedFile $file, AdminUser $admin): array
    {
        if ($election->isRollLocked()) {
            throw ValidationException::withMessages(['roll' => 'Voters’ roll is locked. Unlock it before re-importing.']);
        }

        if (in_array($election->status, [ElectionStatus::Open, ElectionStatus::Closed, ElectionStatus::Certified, ElectionStatus::Locked], true)) {
            throw ValidationException::withMessages(['roll' => 'Cannot import a roll while voting is open or finished.']);
        }

        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename((string) $path);
        $rows = $this->parseSpreadsheet((string) $path);

        return DB::transaction(function () use ($election, $rows, $filename, $admin) {
            ElectionVoter::query()->where('election_id', $election->id)->delete();

            $batch = ElectionVoterImportBatch::query()->create([
                'election_id' => $election->id,
                'filename' => $filename,
                'uploaded_by' => $admin->id,
                'total_rows' => count($rows),
                'status' => 'completed',
            ]);

            $matched = 0;
            $unmatched = 0;
            $ambiguous = 0;
            $seenUsers = [];
            $seenMembershipNumbers = [];

            foreach ($rows as $row) {
                $result = $this->matchRow($row);
                $userId = $result['user_id'];

                if ($userId !== null) {
                    if (isset($seenUsers[$userId])) {
                        $result['match_status'] = ElectionVoterMatchStatus::Ambiguous;
                        $result['user_id'] = null;
                        $result['membership_id'] = null;
                    } else {
                        $seenUsers[$userId] = true;
                    }
                }

                $memNo = MembershipNumberLookup::normalize((string) ($row['membership_number'] ?? ''));
                if ($memNo !== '') {
                    if (isset($seenMembershipNumbers[$memNo])) {
                        $result['match_status'] = ElectionVoterMatchStatus::Ambiguous;
                        $result['user_id'] = null;
                        $result['membership_id'] = null;
                    } else {
                        $seenMembershipNumbers[$memNo] = true;
                    }
                }

                match ($result['match_status']) {
                    ElectionVoterMatchStatus::Matched => $matched++,
                    ElectionVoterMatchStatus::Ambiguous => $ambiguous++,
                    default => $unmatched++,
                };

                ElectionVoter::query()->create([
                    'id' => Uuid::v4(),
                    'election_id' => $election->id,
                    'import_batch_id' => $batch->id,
                    'raw_membership_number' => $row['membership_number'] ?? null,
                    'raw_email' => $row['email'] ?? null,
                    'raw_phone' => $row['phone'] ?? null,
                    'raw_name' => $row['name'] ?? null,
                    'match_status' => $result['match_status'],
                    'user_id' => $result['user_id'],
                    'membership_id' => $result['membership_id'],
                ]);
            }

            $batch->update([
                'matched_rows' => $matched,
                'unmatched_rows' => $unmatched,
                'ambiguous_rows' => $ambiguous,
            ]);

            if ($election->status === ElectionStatus::Draft) {
                $election->update(['status' => ElectionStatus::RollImported]);
            }

            $this->audit->record(
                $election->id,
                ElectionAuditAction::RollImported,
                'admin',
                (string) $admin->id,
                [
                    'filename' => $filename,
                    'matched' => $matched,
                    'unmatched' => $unmatched,
                    'ambiguous' => $ambiguous,
                ],
            );

            return compact('batch', 'matched', 'unmatched', 'ambiguous');
        });
    }

    /**
     * @return list<array{membership_number?: string, email?: string, phone?: string, name?: string}>
     */
    private function parseSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];
        $headers = [];

        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $this->cellToPlainString($cell);
            }

            if ($rowIndex === 1) {
                $headers = array_map(fn ($h) => $this->normalizeHeader($h), $cells);

                continue;
            }

            if ($this->rowIsEmpty($cells)) {
                continue;
            }

            $mapped = [
                'membership_number' => '',
                'email' => '',
                'phone' => '',
                'name' => '',
            ];

            foreach ($headers as $i => $header) {
                $value = $cells[$i] ?? '';
                if ($header === 'membership_number' || $header === 'membership' || $header === 'member_no') {
                    $mapped['membership_number'] = $value;
                } elseif ($header === 'email' || $header === 'email_address') {
                    $mapped['email'] = strtolower($value);
                } elseif ($header === 'phone' || $header === 'mobile' || $header === 'tel') {
                    $mapped['phone'] = preg_replace('/\s+/', '', $value) ?? $value;
                } elseif ($header === 'name' || $header === 'full_name') {
                    $mapped['name'] = $value;
                }
            }

            $rows[] = $mapped;
        }

        return $rows;
    }

    /**
     * Avoid Excel scientific notation (e.g. 2.60977E+11) for phones / long IDs.
     */
    private function cellToPlainString(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return sprintf('%.0f', $value);
        }

        $asString = trim((string) ($value ?? ''));

        // Formula results like ="260977000000"
        if (is_string($value) && str_starts_with($value, '=')) {
            $calculated = $cell->getCalculatedValue();
            if (is_float($calculated) || is_int($calculated)) {
                return sprintf('%.0f', $calculated);
            }

            return trim((string) $calculated);
        }

        if ($asString !== '' && preg_match('/^\d+\.?\d*E[+\-]?\d+$/i', $asString)) {
            return sprintf('%.0f', (float) $asString);
        }

        $formatted = trim((string) $cell->getFormattedValue());
        if ($formatted !== '' && preg_match('/^\d+\.?\d*E[+\-]?\d+$/i', $formatted)) {
            return sprintf('%.0f', (float) $formatted);
        }

        return $asString !== '' ? $asString : $formatted;
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $header) ?? ''));
    }

    /**
     * @param  list<string>  $cells
     */
    private function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Matches a roll row by any of the three identifiers the brief calls out
     * (membership number, email, or phone — PDF §3) — each is independently
     * sufficient, not a fallback chain gated on membership number being
     * present. Every identifier's hits feed into one candidate pool so a
     * row that resolves to more than one distinct member is flagged
     * Ambiguous rather than silently picking one.
     *
     * @param  array{membership_number?: string, email?: string, phone?: string, name?: string}  $row
     * @return array{match_status: string, user_id: ?int, membership_id: ?string}
     */
    private function matchRow(array $row): array
    {
        $candidates = collect();

        $membershipNumber = trim((string) ($row['membership_number'] ?? ''));
        if ($membershipNumber !== '') {
            foreach (MembershipNumberLookup::candidates($membershipNumber) as $candidate) {
                $memberships = Membership::query()
                    ->whereRaw("UPPER(REPLACE(membership_number, '-', '')) = ?", [$candidate])
                    ->get();
                foreach ($memberships as $membership) {
                    $candidates->push([
                        'user_id' => (int) $membership->user_id,
                        'membership_id' => (string) $membership->id,
                    ]);
                }
            }
        }

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '') {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user) {
                $membership = Membership::query()->where('user_id', $user->id)->latest()->first();
                $candidates->push([
                    'user_id' => (int) $user->id,
                    'membership_id' => $membership?->id ? (string) $membership->id : null,
                ]);
            }
        }

        $phone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? '')) ?? '';
        if ($phone !== '') {
            $users = User::query()
                ->whereNotNull('phone')
                ->get()
                ->filter(function (User $user) use ($phone) {
                    $normalized = preg_replace('/\D+/', '', (string) $user->phone) ?? '';

                    return $normalized !== '' && ($normalized === $phone || str_ends_with($normalized, $phone) || str_ends_with($phone, $normalized));
                });

            foreach ($users as $user) {
                $membership = Membership::query()->where('user_id', $user->id)->latest()->first();
                $candidates->push([
                    'user_id' => (int) $user->id,
                    'membership_id' => $membership?->id ? (string) $membership->id : null,
                ]);
            }
        }

        $unique = $candidates->unique('user_id')->values();

        if ($unique->count() === 1) {
            $hit = $unique->first();

            return [
                'match_status' => ElectionVoterMatchStatus::Matched,
                'user_id' => $hit['user_id'],
                'membership_id' => $hit['membership_id'],
            ];
        }

        if ($unique->count() > 1) {
            return [
                'match_status' => ElectionVoterMatchStatus::Ambiguous,
                'user_id' => null,
                'membership_id' => null,
            ];
        }

        return [
            'match_status' => ElectionVoterMatchStatus::Unmatched,
            'user_id' => null,
            'membership_id' => null,
        ];
    }
}
