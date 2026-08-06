<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\TShirtSize;
use App\Models\Membership;
use App\Models\MembershipImportBatch;
use App\Models\MembershipImportRecord;
use App\Models\User;
use App\Notifications\WelcomeImportedMemberNotification;
use App\Support\Uuid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

class MemberImportService
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly MembershipPlanService $planService,
        private readonly SatelliteService $satelliteService,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException if MEMBER_IMPORT_TEMP_PASSWORD isn't configured
     */
    public function importFromFile(string|UploadedFile $file, string $importedBy, bool $sendWelcomeEmail = false): array
    {
        $tempPassword = (string) config('member_import.temp_password');
        if ($tempPassword === '') {
            throw new RuntimeException(
                'MEMBER_IMPORT_TEMP_PASSWORD is not configured. Set it in .env before importing members.'
            );
        }

        $ttlDays = (int) config('member_import.temp_password_ttl_days', 14);
        // Fixed once per batch (not per-row) so every member imported together
        // shares the same cutoff, which is easier to communicate and reason about.
        $tempPasswordExpiresAt = now()->addDays(max(1, $ttlDays));

        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);

        $rows = $this->parseSpreadsheet($path);
        $deduped = $this->dedupeRowsByEmail($rows);

        $duplicatesDropped = count($rows) - count($deduped);

        $batch = MembershipImportBatch::query()->create([
            'uuid' => Uuid::v4(),
            'filename' => $filename,
            'imported_by' => $importedBy,
            'imported_at' => now(),
            'total_rows' => count($deduped),
            'status' => 'completed',
            'notes' => ['errors' => []],
        ]);

        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $errorMessages = [];

        foreach ($deduped as $row) {
            try {
                $result = $this->importRow($row, $batch, $importedBy, $sendWelcomeEmail, $tempPassword, $tempPasswordExpiresAt);
                if ($result['status'] === 'imported') {
                    $imported++;
                    if (! empty($result['warning'])) {
                        $errorMessages[] = $result['warning'];
                    }
                } else {
                    $skipped++;
                    $errorMessages[] = $result['message'];
                }
            } catch (Throwable $e) {
                $errors++;
                $errorMessages[] = ($row['email'] ?? 'unknown').': '.$e->getMessage();
            }
        }

        $batch->update([
            'imported_rows' => $imported,
            'skipped_rows' => $skipped,
            'error_rows' => $errors,
            'notes' => [
                'errors' => $errorMessages,
            ],
        ]);

        return [
            'batchId' => $batch->id,
            'batchUuid' => $batch->uuid,
            'totalRows' => count($deduped),
            'duplicatesDropped' => $duplicatesDropped,
            'importedRows' => $imported,
            'skippedRows' => $skipped,
            'errorRows' => $errors,
            'errors' => $errorMessages,
            'tempPasswordExpiresAt' => $tempPasswordExpiresAt->toDateTimeString(),
            'welcomeEmailSent' => $sendWelcomeEmail,
        ];
    }

    public function rollbackBatch(int $batchId): void
    {
        $batch = MembershipImportBatch::query()->with('records.user')->findOrFail($batchId);

        if ($batch->status === 'rolled_back') {
            throw new \RuntimeException('This import batch has already been rolled back.');
        }

        foreach ($batch->records as $record) {
            if ($record->user && $record->user->first_login !== null) {
                throw new \RuntimeException('Cannot rollback: member '.$record->row_email.' has already logged in.');
            }
        }

        DB::transaction(function () use ($batch): void {
            foreach ($batch->records as $record) {
                if ($record->payment_id) {
                    DB::table('membership_payments')->where('id', $record->payment_id)->delete();
                }
                if ($record->membership_id) {
                    DB::table('membership_history')->where('membership_id', $record->membership_id)->delete();
                    DB::table('memberships')->where('id', $record->membership_id)->delete();
                }
                if ($record->user_id) {
                    User::query()->whereKey($record->user_id)->delete();
                }
            }

            $batch->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
            ]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('All') ?? $spreadsheet->getActiveSheet();
        $rows = [];
        $headers = [];

        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();

                if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                    $dateTime = ExcelDate::excelToDateTimeObject($value);
                    // Excel serializes pure times as a fraction (<1, no whole-day part)
                    // and pure dates as a whole number (no fractional/time part).
                    $cells[] = match (true) {
                        $value < 1 => $dateTime->format('H:i:s'),
                        fmod((float) $value, 1.0) === 0.0 => $dateTime->format('d/m/Y'),
                        default => $dateTime->format('d/m/Y H:i:s'),
                    };

                    continue;
                }

                $cells[] = trim((string) $value);
            }

            if ($rowIndex === 1) {
                $headers = $cells;

                continue;
            }

            if ($cells === [] || ($cells[0] ?? '') === '') {
                continue;
            }

            $mapped = [];
            foreach ($headers as $i => $header) {
                $mapped[$this->normalizeHeader($header)] = $cells[$i] ?? '';
            }

            $rows[] = $this->mapRow($mapped);
        }

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $header)));
    }

    /**
     * Cast a spreadsheet money cell to a float. Cells are read as strings and some
     * rows format "Net amount" with thousands separators (e.g. "1,000.00"), which
     * PHP's (float) cast silently truncates at the comma (=> 1.0 instead of 1000.0).
     * Strip grouping separators before casting so both "1000.00" and "1,000.00" parse
     * the same.
     */
    private function parseAmount(string $value): float
    {
        $cleaned = str_replace(',', '', trim($value));

        return $cleaned === '' ? 0.0 : (float) $cleaned;
    }

    /**
     * @param  array<string, string>  $mapped
     * @return array<string, mixed>
     */
    private function mapRow(array $mapped): array
    {
        return [
            'ref' => $mapped['ref'] ?? '',
            'registrationDate' => $mapped['registration_date'] ?? '',
            'registrationTime' => $mapped['registration_time'] ?? '',
            'name' => $mapped['full_names'] ?? '',
            'email' => strtolower(trim($mapped['email'] ?? '')),
            'phone' => $mapped['phone'] ?? '',
            'status' => $mapped['status'] ?? '',
            'amount' => $this->parseAmount($mapped['net_amount'] ?? ''),
            'gender' => $mapped['sex'] ?? '',
            'nationality' => $mapped['nationality'] ?? '',
            'tShirtSize' => $mapped['t_shirt_size'] ?? '',
            'type' => $mapped['type'] ?? '',
            'satellite' => $mapped['satellite'] ?? '',
            'paymentPlan' => $mapped['payment_plan'] ?? '',
            'town' => trim($mapped['town'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeRowsByEmail(array $rows): array
    {
        $byEmail = [];

        foreach ($rows as $row) {
            $email = $row['email'] ?? '';
            if ($email === '') {
                continue;
            }

            $current = $byEmail[$email] ?? null;
            if ($current === null) {
                $byEmail[$email] = $row;

                continue;
            }

            $currentAt = $this->parseRegisteredAt($current);
            $rowAt = $this->parseRegisteredAt($row);

            if ($rowAt->greaterThanOrEqualTo($currentAt)) {
                $byEmail[$email] = $row;
            }
        }

        return array_values($byEmail);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{status: string, message?: string, warning?: string}
     */
    private function importRow(
        array $row,
        MembershipImportBatch $batch,
        string $importedBy,
        bool $sendWelcomeEmail,
        string $tempPassword,
        Carbon $tempPasswordExpiresAt,
    ): array {
        if (strcasecmp($row['status'] ?? '', 'Paid') !== 0) {
            return ['status' => 'skipped', 'message' => $row['email'].': status not Paid'];
        }

        if ($row['email'] === '' || $row['name'] === '') {
            return ['status' => 'skipped', 'message' => 'Row missing email or name'];
        }

        if (User::query()->where('email', $row['email'])->exists()) {
            return ['status' => 'skipped', 'message' => $row['email'].': email already exists'];
        }

        // Spreadsheet "Ref" cells are bare digits (e.g. "14149") with no LFS
        // prefix, unlike numbers generated for regular signups (LFS-000123)
        // — prefix it here so every membership number starts with "LFS".
        $rawRef = trim((string) ($row['ref'] ?? ''));
        $prefix = strtoupper((string) config('membership.membership_number_prefix', 'LFS'));
        $membershipNumber = ($rawRef !== '' && ! str_starts_with(strtoupper($rawRef), $prefix))
            ? $prefix.$rawRef
            : $rawRef;

        if ($membershipNumber !== '' && Membership::query()->where('membership_number', $membershipNumber)->exists()) {
            return ['status' => 'skipped', 'message' => $row['email'].': membership number '.$membershipNumber.' already exists'];
        }

        $rawTShirtSize = trim((string) ($row['tShirtSize'] ?? ''));
        $tShirtSize = TShirtSize::normalize($rawTShirtSize);
        if ($tShirtSize === null && $rawTShirtSize !== '') {
            return ['status' => 'skipped', 'message' => $row['email'].': unrecognized t-shirt size "'.$rawTShirtSize.'"'];
        }

        $plan = $this->resolvePlan($row['paymentPlan'] ?? '');
        if ($plan === null) {
            return ['status' => 'skipped', 'message' => $row['email'].': unknown payment plan'];
        }

        $rawSatelliteName = trim((string) ($row['satellite'] ?? ''));
        $satellite = $this->satelliteService->findByName($rawSatelliteName);
        $warning = null;
        if ($satellite === null && $rawSatelliteName !== '') {
            $warning = $row['email'].': satellite "'.$rawSatelliteName.'" not recognized, imported without a satellite';
        }

        $registeredAt = $this->parseRegisteredAt($row);

        $user = DB::transaction(function () use ($row, $batch, $importedBy, $tShirtSize, $plan, $satellite, $registeredAt, $tempPassword, $tempPasswordExpiresAt, $membershipNumber) {
            [$otherNames, $lastName] = $this->splitFullName($row['name']);

            $user = User::query()->create([
                'last_name' => $lastName,
                'other_names' => $otherNames,
                'email' => $row['email'],
                'password' => Hash::make($tempPassword),
                'phone' => $row['phone'],
                'gender' => $this->normalizeGender($row['gender'] ?? ''),
                'nationality' => $row['nationality'] ?: null,
                't_shirt_size' => $tShirtSize,
                'town' => $row['town'] ?: null,
                'satellite_id' => $satellite['id'] ?? null,
                'registered_at' => $registeredAt,
                'must_change_password' => true,
                'temp_password_expires_at' => $tempPasswordExpiresAt,
            ]);

            $membership = $this->membershipService->importPaidMembership([
                'userId' => $user->id,
                'planId' => $plan['id'],
                'membershipNumber' => $membershipNumber,
                'registeredAt' => $registeredAt->toDateTimeString(),
                'amountPaid' => $row['amount'] > 0 ? $row['amount'] : $plan['price'],
                'paymentReference' => $membershipNumber,
                'importedBy' => $importedBy,
                'metadata' => [
                    'source' => 'excel_import',
                    'batchId' => $batch->id,
                    'type' => $row['type'],
                    'satelliteName' => $row['satellite'],
                ],
            ]);

            MembershipImportRecord::query()->create([
                'batch_id' => $batch->id,
                'user_id' => $user->id,
                'membership_id' => $membership['membershipId'],
                'payment_id' => $membership['paymentId'],
                'row_ref' => (string) $row['ref'],
                'row_email' => $row['email'],
                'row_payload' => $row,
            ]);

            return $user;
        });

        // Deliberately not sending the verification email here. The signed link
        // expires after auth.verification.expire (60 min default), but members
        // often don't log in with their shared temp password until well after
        // that — sometimes days later, any time inside its multi-day TTL — which
        // left them with a dead "Invalid signature" link before they'd even
        // tried it. AuthenticatedSessionController sends it on first login
        // instead, so the link is fresh relative to when they actually show up.
        if ($sendWelcomeEmail) {
            Notification::send($user, new WelcomeImportedMemberNotification($tempPassword, $tempPasswordExpiresAt));
        }

        $imported = ['status' => 'imported'];
        if ($warning !== null) {
            $imported['warning'] = $warning;
        }

        return $imported;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseRegisteredAt(array $row): Carbon
    {
        $date = $row['registrationDate'] ?? '';
        $time = $row['registrationTime'] ?? '00:00:00';

        try {
            return Carbon::createFromFormat('d/m/Y H:i:s', trim($date).' '.trim($time));
        } catch (Throwable) {
            try {
                return Carbon::createFromFormat('d/m/Y', trim($date))->startOfDay();
            } catch (Throwable) {
                return now();
            }
        }
    }

    /**
     * The sheet only has a single "Full names" column. Split it the same way
     * the last_name/other_names backfill did: the last space-separated word
     * is the surname, everything before it is other names (null if there's
     * only one word).
     *
     * @return array{0: ?string, 1: string} [otherNames, lastName]
     */
    private function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lastName = $parts === [] ? '' : array_pop($parts);
        $otherNames = $parts === [] ? null : implode(' ', $parts);

        return [$otherNames, $lastName];
    }

    private function normalizeGender(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'male' => 'male',
            'female' => 'female',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePlan(string $label): ?array
    {
        $key = strtolower(trim($label));

        $cycle = match (true) {
            str_contains($key, 'quarter') => BillingCycle::Quarterly,
            str_contains($key, 'semi') => BillingCycle::SemiAnnual,
            str_contains($key, 'annual') => BillingCycle::Annual,
            default => null,
        };

        return $cycle ? $this->planService->findByBillingCycle($cycle) : null;
    }
}
