<?php

namespace Tests\Feature;

use App\Jobs\ProcessBulkMusicBrainzMatch;
use App\Jobs\ProcessRecordingAssociation;
use App\Models\Listen;
use App\Models\User;
use App\Services\CatalogMatcher;
use App\Services\CatalogRecordingGroups;
use App\Services\ManualListenMatcher;
use App\Services\MusicBrainzApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BulkMusicBrainzMatchingTest extends TestCase
{
    use RefreshDatabase;

    private function listen(User $user, int $number, string $title = 'Song', ?string $album = 'Album'): Listen
    {
        return Listen::query()->create([
            'user_id' => $user->id, 'source' => 'spotify', 'fingerprint' => hash('sha256', (string) $number),
            'listened_at' => $number, 'track_name' => $title, 'artist_name' => 'Artist', 'release_name' => $album,
            'played_ms' => 1000, 'track_metadata' => [
                'track_name' => $title, 'artist_name' => 'Artist', 'additional_info' => [],
            ],
        ]);
    }

    private function recording(string $id = '22222222-2222-4222-8222-222222222222'): array
    {
        return [
            'id' => $id, 'title' => 'Song',
            'artist-credit' => [['name' => 'Artist', 'artist' => [
                'id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist',
            ]]],
            'releases' => [['title' => 'Album']],
        ];
    }

    private function runJob(int $id): void
    {
        app()->call([new ProcessBulkMusicBrainzMatch($id), 'handle']);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_selection_across_pages_and_select_all_are_snapshotted_and_scoped(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->listen($user, 1);
        $second = $this->listen($user, 2);
        $foreign = $this->listen($other, 3);
        $selection = route('listens.bulk-musicbrainz.selection');
        $this->post($selection, ['action' => 'all'])->assertRedirect(route('login'));
        $this->actingAs($user)->post($selection, ['action' => 'add', 'ids' => [$foreign->id]])->assertSessionHasErrors('selection');
        $this->post($selection, ['action' => 'add', 'ids' => [$first->id]])->assertSessionHasNoErrors();
        $this->post($selection, ['action' => 'add', 'ids' => [$second->id]])->assertSessionHasNoErrors();
        $this->get(route('listens.index'))->assertInertia(fn (Assert $page) => $page->where('bulkSelection.count', 2));
        $this->post($selection, ['action' => 'all'])->assertSessionHasNoErrors();
        $this->post($selection, ['action' => 'remove', 'ids' => [$first->id]])->assertSessionHasNoErrors();
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1'])->assertSessionHasNoErrors();
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        $this->listen($user, 4);
        $this->assertSame(1, $run->total);
        $this->assertDatabaseHas('bulk_musicbrainz_run_items', ['run_id' => $run->id, 'listen_id' => $second->id]);
        $this->assertDatabaseCount('bulk_musicbrainz_run_items', 1);
        Queue::assertPushed(ProcessBulkMusicBrainzMatch::class);
        $this->actingAs($other)->post(route('listens.bulk-musicbrainz.cancel', $run->id))->assertNotFound();
    }

    public function test_unique_exact_results_match_but_ambiguous_or_incomplete_results_do_not(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        Sleep::fake();
        $user = User::factory()->create();
        $one = $this->listen($user, 1, 'Song', null);
        $two = $this->listen($user, 2, 'Ambiguous');
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1'])->assertSessionHasNoErrors();
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => function (Request $request) {
            if (str_contains($request['query'], 'Ambiguous')) {
                return Http::response(['count' => 101, 'recordings' => [$this->recording()]]);
            }

            return Http::response(['count' => 1, 'recordings' => [$this->recording()]]);
        }]);
        $this->runJob($run->id);
        $this->runJob($run->id);
        $this->runJob($run->id);
        $this->assertSame($this->recording()['id'], $one->fresh()->recording_mbid);
        $this->assertNull($two->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', [
            'id' => $run->id, 'status' => 'completed', 'processed' => 2, 'matched' => 1, 'unmatched' => 1,
        ]);
        $this->assertDatabaseCount('pending_musicbrainz_matches', 0);
        Http::assertSentCount(2);
    }

    public function test_review_shows_only_the_current_users_latest_bulk_run_auto_matches(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $matched = $this->listen($user, 1, 'Song');
        $pending = $this->listen($user, 2, 'Ambiguous');
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => function (Request $request) {
            if (str_contains($request['query'], 'Ambiguous')) {
                $first = $this->recording();
                $first['title'] = 'Ambiguous';
                $second = $first;
                $second['id'] = '33333333-3333-4333-8333-333333333333';

                return Http::response(['count' => 2, 'recordings' => [$first, $second]]);
            }

            return Http::response(['count' => 1, 'recordings' => [$this->recording()]]);
        }]);
        $this->runJob($run->id);
        $this->runJob($run->id);
        $this->runJob($run->id);
        $this->withoutVite();
        $this->get(route('listens.bulk-musicbrainz.review'))->assertInertia(fn (Assert $page) => $page
            ->where('bulkRun.id', $run->id)->where('bulkRun.matched', 1)
            ->where('autoMatched.total', 1)->where('autoMatched.data.0.id', $matched->id)
            ->where('autoMatched.data.0.recording_mbid', $this->recording()['id'])
            ->where('pending.total', 1)->where('pending.data.0.listen_id', $pending->id));
        $this->actingAs($other)->get(route('listens.bulk-musicbrainz.review'))->assertInertia(fn (Assert $page) => $page
            ->where('bulkRun', null)->where('autoMatched.total', 0)->where('pending.total', 0));
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'add', 'ids' => [$pending->id]]);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $this->get(route('listens.bulk-musicbrainz.review'))->assertInertia(fn (Assert $page) => $page
            ->where('bulkRun.status', 'queued')->where('autoMatched.total', 0)->where('pending.total', 1));
    }

    public function test_auto_matches_and_pending_reviews_paginate_independently(): void
    {
        $user = User::factory()->create();
        $run = DB::table('bulk_musicbrainz_runs')->insertGetId([
            'user_id' => $user->id, 'status' => 'completed', 'matched' => 27,
        ]);
        for ($number = 1; $number <= 27; $number++) {
            $listen = $this->listen($user, $number);
            $listen->update(['recording_mbid' => $this->recording()['id']]);
            DB::table('bulk_musicbrainz_run_items')->insert([
                'run_id' => $run, 'listen_id' => $listen->id, 'status' => 'matched',
            ]);
            DB::table('pending_musicbrainz_matches')->insert([
                'user_id' => $user->id, 'listen_id' => $listen->id,
                'track_name' => 'Song', 'artist_name' => 'Artist', 'release_name' => 'Album',
                'candidates' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $listen->delete();
        $this->withoutVite()->actingAs($user)->get(route('listens.bulk-musicbrainz.review', [
            'matched_page' => 2, 'page' => 1,
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('bulkRun.matched', 27)->where('autoMatched.total', 26)
            ->where('autoMatched.current_page', 2)->has('autoMatched.data', 1)
            ->where('pending.current_page', 1)->has('pending.data', 25)
            ->where('autoMatched.links.1.url', route('listens.bulk-musicbrainz.review', ['page' => 1, 'matched_page' => 1]))
            ->where('pending.links.2.url', route('listens.bulk-musicbrainz.review', ['matched_page' => 2, 'page' => 2])));
    }

    public function test_rate_limits_retry_then_pause_and_can_resume_or_cancel(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        Sleep::fake();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response([], 503, ['Retry-After' => '60'])]);
        $this->runJob($run->id);
        $row = DB::table('bulk_musicbrainz_runs')->where('id', $run->id)->first();
        $this->assertSame('waiting', $row->status);
        $this->assertGreaterThanOrEqual(now()->addSeconds(59)->timestamp, strtotime($row->retry_at));
        $this->assertNull($listen->fresh()->recording_mbid);
        DB::table('bulk_musicbrainz_run_items')->where('run_id', $run->id)->update(['attempts' => 4]);
        $this->travel(61)->seconds();
        $this->runJob($run->id);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'status' => 'paused']);
        $this->post(route('listens.bulk-musicbrainz.resume', $run->id))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'status' => 'queued']);
        $this->post(route('listens.bulk-musicbrainz.cancel', $run->id))->assertSessionHasNoErrors();
        $this->runJob($run->id);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'status' => 'cancelled', 'processed' => 0]);
        Http::assertSentCount(2);
    }

    public function test_existing_manual_match_is_not_overwritten(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        Sleep::fake();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $manual = app(ManualListenMatcher::class);
        DB::table('listen_matches')->insert([
            'user_id' => $user->id, 'song_key' => $manual->key('Song', 'Artist'),
            'match' => json_encode(['recording_mbid' => '33333333-3333-4333-8333-333333333333'], JSON_THROW_ON_ERROR),
        ]);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$this->recording()]])]);
        $this->runJob($run->id);
        $this->assertNull($listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'skipped' => 1]);
    }

    public function test_cancellation_during_api_request_prevents_writes(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => function () use ($run) {
            $this->post(route('listens.bulk-musicbrainz.cancel', $run->id))->assertSessionHasNoErrors();

            return Http::response(['count' => 1, 'recordings' => [$this->recording()]]);
        }]);
        $this->runJob($run->id);
        $this->assertNull($listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'status' => 'cancelled', 'processed' => 0]);
    }

    public function test_identical_selected_listens_reuse_lookup_and_deleted_items_are_skipped(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $first = $this->listen($user, 1);
        $second = $this->listen($user, 2);
        $deleted = $this->listen($user, 3);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        $deleted->delete();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$this->recording()]])]);
        $this->runJob($run->id);
        $this->runJob($run->id);
        $this->runJob($run->id);
        $this->runJob($run->id);
        $this->assertSame($this->recording()['id'], $first->fresh()->recording_mbid);
        $this->assertSame($this->recording()['id'], $second->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'status' => 'completed', 'matched' => 2, 'skipped' => 1]);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_and_incomplete_album_evidence_are_not_matched(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $api = app(MusicBrainzApi::class);
        $wrongAlbum = $this->recording('33333333-3333-4333-8333-333333333333');
        $wrongAlbum['releases'] = [];
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::sequence()
            ->push(['count' => 2, 'recordings' => [$this->recording(), $wrongAlbum]])
            ->push(['count' => 2, 'recordings' => [$this->recording(), $this->recording('33333333-3333-4333-8333-333333333333')]])]);
        $this->assertNull($api->matchListenRecording('Song', 'Artist', 'Album'));
        $this->assertNull($api->matchListenRecording('Song', 'Artist', ''));
        Http::assertSentCount(2);
    }

    public function test_cancelled_and_unconfirmed_runs_cannot_send_requests(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), [])->assertSessionHasErrors('confirm');
        $this->assertDatabaseCount('bulk_musicbrainz_runs', 0);
        Http::assertNothingSent();
    }

    public function test_linked_catalog_listens_propagate_without_an_additional_api_request(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $first = $this->listen($user, 1);
        $second = $this->listen($user, 2, 'Other title');
        $second->update(['source' => 'apple']);
        foreach ([[$first, 'spotify', '0123456789ABCDEFGHIJKL'], [$second, 'apple', '123']] as [$listen, $source, $id]) {
            $catalog = app(CatalogMatcher::class)->match($source, $id, $listen->track_name, 'Artist', 'Album', 180000, 'import');
            $metadata = $listen->track_metadata;
            $metadata['additional_info']['catalogs'] = [$source => $catalog];
            $listen->update(['track_metadata' => $metadata]);
        }
        app(CatalogRecordingGroups::class)->merge($user, $first->fresh(), $second->fresh());
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'add', 'ids' => [$first->id]]);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$this->recording()]])]);
        $this->runJob($run->id);
        $this->assertSame($this->recording()['id'], $second->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'matched' => 1, 'propagated' => 1]);
        Http::assertSentCount(1);
    }

    public function test_database_queue_continues_under_single_try_worker(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $first = $this->listen($user, 1);
        $second = $this->listen($user, 2);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1'])->assertSessionHasNoErrors();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$this->recording()]])]);
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('queue:work', ['connection' => 'imports', '--queue' => 'imports', '--once' => true, '--tries' => 1, '--sleep' => 0])->assertExitCode(0);
        }
        $this->assertSame($this->recording()['id'], $first->fresh()->recording_mbid);
        $this->assertSame($this->recording()['id'], $second->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['status' => 'completed', 'matched' => 2]);
        Http::assertSentCount(1);
    }

    public function test_linked_listens_with_conflicting_manual_choices_are_not_overwritten(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $first = $this->listen($user, 1);
        $second = $this->listen($user, 2, 'Other title');
        $second->update(['source' => 'apple']);
        foreach ([[$first, 'spotify', '0123456789ABCDEFGHIJKL'], [$second, 'apple', '123']] as [$listen, $source, $id]) {
            $catalog = app(CatalogMatcher::class)->match($source, $id, $listen->track_name, 'Artist', 'Album', 180000, 'import');
            $metadata = $listen->track_metadata;
            $metadata['additional_info']['catalogs'] = [$source => $catalog];
            $listen->update(['track_metadata' => $metadata]);
        }
        app(CatalogRecordingGroups::class)->merge($user, $first->fresh(), $second->fresh());
        $manual = app(ManualListenMatcher::class);
        DB::table('listen_matches')->insert([
            'user_id' => $user->id, 'song_key' => $manual->key('Other title', 'Artist'),
            'match' => json_encode(['recording_mbid' => '33333333-3333-4333-8333-333333333333'], JSON_THROW_ON_ERROR),
        ]);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'add', 'ids' => [$first->id]]);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$this->recording()]])]);
        $this->runJob($run->id);
        $this->assertNull($first->fresh()->recording_mbid);
        $this->assertNull($second->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'skipped' => 1, 'matched' => 0]);
    }

    public function test_malformed_api_response_fails_the_run_without_matching(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [['id' => 'invalid']]])]);
        $this->artisan('queue:work', ['connection' => 'imports', '--queue' => 'imports', '--once' => true, '--tries' => 1, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['status' => 'failed', 'processed' => 0]);
        $this->assertNull($listen->fresh()->recording_mbid);
    }

    public function test_retry_after_http_date_is_honored_for_rate_limits(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response([], 429, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 120).' GMT'])]);
        $this->runJob($run->id);
        $row = DB::table('bulk_musicbrainz_runs')->where('id', $run->id)->first();
        $this->assertSame('waiting', $row->status);
        $this->assertGreaterThanOrEqual(now()->addSeconds(118)->timestamp, strtotime($row->retry_at));
        Http::assertSentCount(1);
    }

    public function test_retry_after_is_honored_for_other_transient_server_errors(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response([], 502, ['Retry-After' => '120'])]);

        $this->runJob($run->id);

        $row = DB::table('bulk_musicbrainz_runs')->where('id', $run->id)->first();
        $this->assertSame('waiting', $row->status);
        $this->assertGreaterThanOrEqual(now()->addSeconds(119)->timestamp, strtotime($row->retry_at));
        Http::assertSentCount(1);
    }

    public function test_rate_limit_without_retry_after_is_retried(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response([], 503)]);

        $this->runJob($run->id);

        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'status' => 'waiting', 'processed' => 0]);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_results_are_saved_per_listen_and_can_be_approved(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->listen($user, 1);
        $second = $this->listen($user, 2);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        $chosen = '33333333-3333-4333-8333-333333333333';
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response([
            'count' => 2, 'recordings' => [$this->recording(), $this->recording($chosen)],
        ])]);

        $this->runJob($run->id);
        $this->runJob($run->id);

        $this->assertNull($first->fresh()->recording_mbid);
        $this->assertNull($second->fresh()->recording_mbid);
        $this->assertDatabaseCount('pending_musicbrainz_matches', 2);
        Http::assertSentCount(1);
        $review = DB::table('pending_musicbrainz_matches')->where('listen_id', $first->id)->first();
        $this->withoutVite();
        $this->get(route('listens.bulk-musicbrainz.review'))->assertInertia(fn (Assert $page) => $page
            ->where('pending.total', 2)->has('pending.data.0.candidates', 2));
        $this->actingAs($other)->post(route('listens.bulk-musicbrainz.approve', $review->id), ['recording_mbid' => $chosen])->assertNotFound();
        $this->delete(route('listens.bulk-musicbrainz.dismiss', $review->id))->assertNotFound();
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.approve', $review->id), [
            'recording_mbid' => '44444444-4444-4444-8444-444444444444',
        ])->assertSessionHasErrors('recording_mbid');
        $this->post(route('listens.bulk-musicbrainz.approve', $review->id), ['recording_mbid' => $chosen])->assertSessionHasNoErrors();
        $this->assertNull($first->fresh()->recording_mbid);
        $this->post(route('listens.bulk-musicbrainz.approve', $review->id), ['recording_mbid' => $chosen])
            ->assertSessionHasErrors('recording_mbid');
        $this->delete(route('listens.bulk-musicbrainz.dismiss', $review->id))->assertSessionHasErrors('recording_mbid');
        $this->processRecordingAssociation();
        $this->assertSame($chosen, $first->fresh()->recording_mbid);
        $this->assertNull($second->fresh()->recording_mbid);
        $this->assertDatabaseMissing('pending_musicbrainz_matches', ['id' => $review->id]);
        Http::assertSentCount(1);
    }

    public function test_incomplete_candidates_require_review_and_changed_listens_cannot_be_approved(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response([
            'count' => 101, 'recordings' => [$this->recording()],
        ])]);
        $this->runJob($run->id);
        $review = DB::table('pending_musicbrainz_matches')->sole();
        $this->assertTrue((bool) $review->incomplete);
        $listen->update(['track_name' => 'Changed']);

        $this->post(route('listens.bulk-musicbrainz.approve', $review->id), [
            'recording_mbid' => $this->recording()['id'],
        ])->assertSessionHasErrors('recording_mbid');
        $this->assertNull($listen->fresh()->recording_mbid);
        $this->delete(route('listens.bulk-musicbrainz.dismiss', $review->id))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('pending_musicbrainz_matches', ['id' => $review->id]);
    }

    public function test_unverified_album_candidate_is_saved_and_conflicting_manual_choice_blocks_approval(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        Sleep::fake();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        $recording = $this->recording();
        $recording['releases'] = [];
        $other = $recording;
        $other['id'] = '44444444-4444-4444-8444-444444444444';
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::sequence()
            ->push(['count' => 0, 'recordings' => []])
            ->push(['count' => 2, 'recordings' => [$recording, $other]])]);

        $this->runJob($run->id);

        $review = DB::table('pending_musicbrainz_matches')->sole();
        $candidate = json_decode($review->candidates, true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertFalse($candidate['album_verified']);
        $manual = app(ManualListenMatcher::class);
        DB::table('listen_matches')->insert([
            'user_id' => $user->id, 'song_key' => $manual->key('Song', 'Artist'),
            'match' => json_encode(['recording_mbid' => '33333333-3333-4333-8333-333333333333'], JSON_THROW_ON_ERROR),
        ]);
        $this->post(route('listens.bulk-musicbrainz.approve', $review->id), [
            'recording_mbid' => $recording['id'],
        ])->assertSessionHasNoErrors();
        $job = new ProcessRecordingAssociation(DB::table('recording_association_runs')->value('id'));
        try {
            app()->call([$job, 'handle']);
            $this->fail('A conflicting saved match must fail.');
        } catch (ValidationException $exception) {
            $job->failed($exception);
        }
        $this->assertDatabaseHas('recording_association_runs', [
            'status' => 'failed', 'error' => 'This choice conflicts with a saved or linked match. Resolve the conflict first.',
        ]);
        $this->withoutVite();
        $this->get(route('listens.bulk-musicbrainz.review'))->assertInertia(fn (Assert $page) => $page
            ->where('pending.data.0.association.status', 'failed')
            ->where('pending.data.0.association.error', 'This choice conflicts with a saved or linked match. Resolve the conflict first.'));
        $this->assertNull($listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('pending_musicbrainz_matches', ['id' => $review->id]);
        Http::assertSentCount(2);
    }

    public function test_missing_album_match_falls_back_to_unique_exact_title_and_artist(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        Sleep::fake();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        $recording = $this->recording();
        $recording['releases'] = [['title' => 'Different album']];
        Http::fake(['musicbrainz.org/ws/2/recording?*' => function (Request $request) use ($recording) {
            return str_contains($request['query'], 'release:')
                ? Http::response(['count' => 0, 'recordings' => []])
                : Http::response(['count' => 1, 'recordings' => [$recording]]);
        }]);

        $this->runJob($run->id);

        $this->assertSame($recording['id'], $listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'matched' => 1]);
        $this->assertDatabaseCount('pending_musicbrainz_matches', 0);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => ! str_contains($request['query'], 'release:'));
        Sleep::assertSleptTimes(1);
    }

    public function test_unverified_album_result_is_rechecked_without_album(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $recording = $this->recording();
        $recording['releases'] = [];
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::sequence()
            ->push(['count' => 1, 'recordings' => [$recording]])
            ->push(['count' => 1, 'recordings' => [$recording]])]);

        $match = app(MusicBrainzApi::class)->matchListenRecording('Song', 'Artist', 'Album');

        $this->assertSame($recording['id'], $match['recording_mbid']);
        Http::assertSentCount(2);
    }

    public function test_fallback_does_not_match_a_different_artist_or_truncated_response(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $recording = $this->recording();
        $recording['artist-credit'][0]['name'] = 'Other artist';
        $wrongTitle = $this->recording('33333333-3333-4333-8333-333333333333');
        $wrongTitle['title'] = 'Other song';
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::sequence()
            ->push(['count' => 0, 'recordings' => []])
            ->push(['count' => 2, 'recordings' => [$recording, $wrongTitle]])
            ->push(['count' => 101, 'recordings' => [$this->recording()]])]);
        $api = app(MusicBrainzApi::class);

        $this->assertNull($api->matchListenRecording('Song', 'Artist', 'Album'));
        $this->assertNull($api->matchListenRecording('Song', 'Artist', 'Album'));
        Http::assertSentCount(3);
    }

    public function test_rate_limit_on_fallback_retries_without_matching(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        Sleep::fake();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::sequence()
            ->push(['count' => 0, 'recordings' => []])
            ->push([], 503, ['Retry-After' => '60'])]);

        $this->runJob($run->id);

        $this->assertNull($listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'status' => 'waiting', 'processed' => 0]);
        Http::assertSentCount(2);
    }

    public function test_truncated_fallback_results_are_saved_for_review(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        Sleep::fake();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::sequence()
            ->push(['count' => 0, 'recordings' => []])
            ->push(['count' => 101, 'recordings' => [$this->recording()]])]);

        $this->runJob($run->id);

        $this->assertNull($listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('pending_musicbrainz_matches', ['listen_id' => $listen->id, 'incomplete' => true]);
        Http::assertSentCount(2);
    }

    public function test_cancelled_run_does_not_start_the_fallback_request(): void
    {
        Queue::fake([ProcessBulkMusicBrainzMatch::class]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $listen = $this->listen($user, 1);
        $this->actingAs($user)->post(route('listens.bulk-musicbrainz.selection'), ['action' => 'all']);
        $this->post(route('listens.bulk-musicbrainz.store'), ['confirm' => '1']);
        $run = DB::table('bulk_musicbrainz_runs')->sole();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => function () use ($run) {
            $this->post(route('listens.bulk-musicbrainz.cancel', $run->id))->assertSessionHasNoErrors();

            return Http::response(['count' => 0, 'recordings' => []]);
        }]);

        $this->runJob($run->id);

        $this->assertNull($listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('bulk_musicbrainz_runs', ['id' => $run->id, 'status' => 'cancelled', 'processed' => 0]);
        Http::assertSentCount(1);
    }
}
