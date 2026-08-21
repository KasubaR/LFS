<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\ElectionStatus;
use App\Enums\ElectionType;
use App\Enums\ElectionVoterMatchStatus;
use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionPosition;
use App\Models\ElectionVoter;
use App\Models\User;
use App\Support\Uuid;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ElectionTestSeeder extends Seeder
{
    public function run(): void
    {
        // This seeder creates real admin accounts with published default
        // passwords (see docs/ELECTIONS_RUNBOOK.md) — it exists for UAT on
        // local/staging only and must never quietly create a backdoor in a
        // live environment.
        if (app()->environment('production')) {
            $this->command?->error('ElectionTestSeeder refuses to run in production. Seed on local/staging only.');

            return;
        }

        $ec = AdminUser::query()->firstOrCreate(
            ['email' => 'ec@lfszambia.run'],
            [
                'name' => 'Electoral Commission',
                'password' => Hash::make('ChangeMe-EC-2026!'),
                'role' => AdminRole::ElectoralCommission,
                'is_active' => true,
            ]
        );

        $observer = AdminUser::query()->firstOrCreate(
            ['email' => 'observer@lfszambia.run'],
            [
                'name' => 'Election Observer',
                'password' => Hash::make('ChangeMe-Observer-2026!'),
                'role' => AdminRole::ElectionObserver,
                'is_active' => true,
            ]
        );

        $election = Election::query()->create([
            'id' => Uuid::v4(),
            'title' => 'Test EGM By-election',
            'type' => ElectionType::Egm,
            'status' => ElectionStatus::Draft,
            'description' => 'Seeded test election for EC UAT.',
            'scheduled_open_at' => now()->addDays(3),
            'scheduled_close_at' => now()->addDays(3)->addHours(4),
            'quorum_percent' => 50,
        ]);

        $position = ElectionPosition::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'title' => 'Committee Chair',
            'allow_abstain' => false,
            'sort_order' => 1,
        ]);

        foreach (['Alex Candidate', 'Blake Candidate', 'Casey Candidate'] as $i => $name) {
            ElectionCandidate::query()->create([
                'id' => Uuid::v4(),
                'position_id' => $position->id,
                'name' => $name,
                'sort_order' => $i + 1,
                'is_active' => true,
            ]);
        }

        $users = User::query()->limit(5)->get();
        foreach ($users as $user) {
            ElectionVoter::query()->create([
                'id' => Uuid::v4(),
                'election_id' => $election->id,
                'raw_email' => $user->email,
                'raw_name' => trim(($user->other_names ?? '').' '.($user->last_name ?? '')),
                'match_status' => ElectionVoterMatchStatus::Matched,
                'user_id' => $user->id,
            ]);
        }

        if ($users->isNotEmpty()) {
            $election->update(['status' => ElectionStatus::RollImported]);
        }

        $this->command?->info("Test election {$election->id} seeded. EC admin: {$ec->email}; observer: {$observer->email}");
    }
}
