<?php

use App\Support\Uuid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces Meeting as an entity of its own, separate from Election.
 *
 * This is an "expand" migration only: it adds the new meetings/
 * meeting_attendances tables, links every existing election to a
 * backfilled meeting, and copies existing check-ins across — but leaves
 * elections.quorum_percent/quorum_confirmed_at and election_attendances
 * in place untouched. Those are only read/written by the pre-refactor
 * ElectionQuorumService, which keeps working unmodified until the
 * follow-up "contract" migration removes them once every caller has
 * switched to the meeting-scoped service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('type', 32)->default('general');
            $table->string('status', 32)->default('scheduled');
            $table->text('description')->nullable();
            $table->dateTime('scheduled_starts_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedTinyInteger('quorum_percent')->default(50);
            $table->dateTime('quorum_confirmed_at')->nullable();
            $table->string('hms_room_id')->nullable();
            $table->string('hms_room_code_host')->nullable();
            $table->string('hms_room_code_guest')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('meeting_attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('meeting_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('checked_in_at');
            $table->string('method', 16)->default('manual');
            $table->string('hms_peer_id')->nullable();
            $table->timestamps();

            $table->foreign('meeting_id')->references('id')->on('meetings')->cascadeOnDelete();
            $table->unique(['meeting_id', 'user_id']);
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->uuid('meeting_id')->nullable()->after('id');
            $table->foreign('meeting_id')->references('id')->on('meetings')->nullOnDelete();
        });

        $this->backfillMeetingsForExistingElections();
    }

    private function backfillMeetingsForExistingElections(): void
    {
        $now = now();

        foreach (DB::table('elections')->orderBy('created_at')->get() as $election) {
            $meetingId = Uuid::v4();

            DB::table('meetings')->insert([
                'id' => $meetingId,
                'title' => $election->title,
                // by_election ballots in this system are always conducted
                // during a virtual EGM per the developer brief, so that's
                // the closest honest default; admins can edit it after.
                'type' => match ($election->type) {
                    'agm' => 'agm',
                    'egm' => 'egm',
                    default => 'egm',
                },
                'status' => in_array($election->status, ['closed', 'certified', 'locked'], true)
                    ? 'ended'
                    : ($election->status === 'open' ? 'live' : 'scheduled'),
                'quorum_percent' => $election->quorum_percent,
                'quorum_confirmed_at' => $election->quorum_confirmed_at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('elections')->where('id', $election->id)->update(['meeting_id' => $meetingId]);

            foreach (DB::table('election_attendances')->where('election_id', $election->id)->get() as $attendance) {
                DB::table('meeting_attendances')->insert([
                    'id' => Uuid::v4(),
                    'meeting_id' => $meetingId,
                    'user_id' => $attendance->user_id,
                    'checked_in_at' => $attendance->checked_in_at,
                    'method' => 'manual',
                    'created_at' => $attendance->created_at,
                    'updated_at' => $attendance->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropForeign(['meeting_id']);
            $table->dropColumn('meeting_id');
        });

        Schema::dropIfExists('meeting_attendances');
        Schema::dropIfExists('meetings');
    }
};
