<?php

namespace Tests\Feature;

use App\Jobs\ProcessStreamingHistoryImport;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\ImportControl;
use App\Services\StreamingHistoryUpload;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AsyncImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicbrainz.enabled' => false]);
    }

    private function history(int $count = 1): UploadedFile
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000 + $i,
                'master_metadata_track_name' => 'Track',
                'master_metadata_album_artist_name' => 'Artist',
                'master_metadata_album_album_name' => 'Album',
                'spotify_track_uri' => '',
            ];
        }

        return UploadedFile::fake()->createWithContent('history.json', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_upload_is_queued_then_processed_and_files_are_retained_for_retry(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('imports.store'), ['files' => [$this->history()]]);

        $response->assertRedirect(route('imports.index', ['started' => 1]));
        $run = ImportRun::query()->sole();
        $this->assertSame('queued', $run->status);
        Storage::disk('local')->assertExists($run->files[0]['path']);
        $this->assertDatabaseCount('listens', 0);
        Queue::assertPushed(ProcessStreamingHistoryImport::class, fn ($job) => $job->importId === $run->id && $job->connection === 'imports');

        app()->call([(new ProcessStreamingHistoryImport($run->id)), 'handle']);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(['imported' => 1, 'duplicates' => 0, 'matched' => 0, 'errors' => []], $run->result);
        Storage::disk('local')->assertExists($run->files[0]['path']);
        $this->assertDatabaseCount('listens', 1);
    }

    public function test_import_progress_is_logged(): void
    {
        Queue::fake();
        Storage::fake('local');
        Log::spy();
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();

        app()->call([(new ProcessStreamingHistoryImport($run->id)), 'handle']);

        Log::shouldHaveReceived('info')->with('Import started.', ['import_id' => $run->id, 'user_id' => $run->user_id, 'files' => 1])->once();
        Log::shouldHaveReceived('info')->with('Import progress.', ['import_id' => $run->id, 'file' => 'history.json', 'current' => 1, 'total' => 1, 'processed' => 1]);
        Log::shouldHaveReceived('info')->with('Import completed.', [
            'import_id' => $run->id, 'status' => 'completed', 'imported' => 1, 'duplicates' => 0, 'matched' => 0, 'errors' => 0,
        ])->once();
    }

    public function test_progress_page_is_private_and_reports_running_status(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $run->update(['status' => 'running']);
        Cache::store('file')->put('import-progress:'.$run->id, ['file' => 'history.json', 'current' => 1, 'total' => 1, 'processed' => 100], 60);

        $this->get(route('imports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('imports/index')->where('imports.data.0.status', 'running')
            ->where('imports.data.0.progress.processed', 100)->missing('imports.data.0.files'));
        $this->actingAs($other)->get(route('imports.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('imports.data', 0));
        $this->assertGuestCannotAccessImports();
        Cache::store('file')->forget('import-progress:'.$run->id);
    }

    private function assertGuestCannotAccessImports(): void
    {
        auth()->logout();
        $this->get(route('imports.index'))->assertRedirect(route('login'));
        $this->post(route('imports.store'))->assertRedirect(route('login'));
    }

    public function test_processed_record_progress_is_reported_before_file_completion(): void
    {
        $observed = [];

        $result = app(StreamingHistoryUpload::class)->import(User::factory()->create(), [$this->history(101)],
            function (array $progress) use (&$observed): void {
                $observed[] = $progress['processed'];
            });

        $this->assertSame([0, 100, 101], $observed);
        $this->assertSame(101, $result['imported']);
        $this->assertDatabaseCount('listens', 101);
    }

    public function test_invalid_files_are_reported_in_the_completed_import(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [
            $this->history(), UploadedFile::fake()->createWithContent('unknown.txt', 'unsupported'),
        ]]);
        $run = ImportRun::query()->sole();

        app()->call([(new ProcessStreamingHistoryImport($run->id)), 'handle']);

        $run->refresh();
        $this->assertSame('completed_with_errors', $run->status);
        $this->assertSame('unknown.txt', $run->result['errors'][0]['file']);
        $this->assertDatabaseCount('listens', 1);
    }

    public function test_database_queue_worker_processes_an_uploaded_import(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]])
            ->assertRedirect(route('imports.index', ['started' => 1]));
        $this->assertDatabaseHas('jobs', ['queue' => 'imports']);
        $this->assertDatabaseCount('listens', 0);

        $this->artisan('queue:work', ['connection' => 'imports', '--queue' => 'imports', '--once' => true, '--timeout' => 7200])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('jobs', ['queue' => 'imports']);
        $this->assertSame('completed', ImportRun::query()->sole()->status);
        $this->assertDatabaseCount('listens', 1);
    }

    public function test_failed_job_reports_failure_and_keeps_uploaded_files_for_retry(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $job = new ProcessStreamingHistoryImport($run->id);

        $job->failed(new \RuntimeException('private failure details'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringNotContainsString('private failure details', $run->error);
        Storage::disk('local')->assertExists($run->files[0]['path']);
        $this->assertDatabaseCount('listens', 0);
    }

    public function test_deletion_is_blocked_while_an_import_is_pending(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('imports.store'), ['files' => [$this->history()]]);

        $this->delete(route('listens.destroy'))->assertSessionHasErrors(['history' => 'Wait for your active imports to finish before deleting listening history.']);
        $this->assertSame('queued', ImportRun::query()->sole()->status);
    }

    public function test_unverified_users_can_view_but_not_start_imports(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get(route('imports.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canImport', false));
        $this->post(route('imports.store'), ['files' => [$this->history()]])->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('import_runs', 0);
    }

    public function test_import_page_and_successful_upload_do_not_write_database_sessions(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        app('session')->forgetDrivers();
        $user = User::factory()->create();
        ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'running', 'files' => []]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($user)->get(route('imports.index'))->assertOk();
        $reads = $queries;
        $queries = [];
        $this->post(route('imports.store'), ['files' => [$this->history()]])
            ->assertRedirect(route('imports.index', ['started' => 1]));

        $readWrites = array_filter($reads, fn ($sql) => preg_match('/^(insert|update|delete).*\\bsessions\\b/', $sql) === 1);
        $this->assertSame([], array_values($readWrites));
        $writes = array_filter($queries, fn ($sql) => preg_match('/^(insert|update|delete).*\\bsessions\\b/', $sql) === 1);
        $this->assertSame([], array_values($writes));
        Queue::assertPushed(ProcessStreamingHistoryImport::class);
    }

    public function test_import_page_ages_existing_flash_data_with_database_sessions(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        app('session')->forgetDrivers();
        $session = app('session')->driver();
        $session->start();
        $session->flash('notice', 'Temporary message');
        $session->save();
        $user = User::factory()->create();
        ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'running', 'files' => []]);
        $this->withCookie($session->getName(), $session->getId())
            ->actingAs($user)
            ->get(route('imports.index'))->assertOk();

        $this->get(route('imports.index'))->assertOk()->assertSessionMissing('notice');
    }

    public function test_import_page_refreshes_database_session_when_no_import_is_active(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        app('session')->forgetDrivers();

        $this->actingAs(User::factory()->create())->get(route('imports.index'))->assertOk();

        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_user_can_request_abort_of_their_running_import(): void
    {
        $user = User::factory()->create();
        $run = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'running', 'files' => []]);
        $control = app(ImportControl::class);
        $control->markAbortable($run->id);

        try {
            $this->actingAs($user)->post(route('imports.abort', $run->id))->assertRedirect(route('imports.index'));
            $this->assertTrue($control->isAborting($run->id));
            $this->get(route('imports.index'))->assertInertia(fn (Assert $page) => $page->where('imports.data.0.status', 'aborting'));
        } finally {
            $control->clearAbort($run->id);
            $control->clearAbortable($run->id);
        }
    }

    public function test_abort_rolls_back_the_current_file_but_keeps_completed_files(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('imports.store'), ['files' => [$this->history(), $this->history(150)]]);
        $run = ImportRun::query()->sole();
        $count = 0;
        DB::listen(function ($query) use (&$count, $run): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "listens"') && ++$count === 50) {
                app(ImportControl::class)->requestAbort($run->id);
            }
        });

        app()->call([(new ProcessStreamingHistoryImport($run->id)), 'handle']);

        $this->assertSame('aborted', $run->refresh()->status);
        $this->assertDatabaseCount('listens', 1);
        Storage::disk('local')->assertExists($run->files[0]['path']);
        $this->assertFalse(app(ImportControl::class)->isAborting($run->id));
        Queue::assertPushed(ProcessStreamingHistoryImport::class);
    }

    public function test_force_abort_requires_workers_to_be_stopped_explicitly(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $run->update(['status' => 'running']);

        $this->artisan('imports:abort-running')->assertFailed()
            ->expectsOutputToContain('Stop all imports workers');

        $this->assertSame('running', $run->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
        Storage::disk('local')->assertExists($run->files[0]['path']);
    }

    public function test_force_abort_removes_only_matching_jobs_and_keeps_listens_and_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('listens.import'), ['source' => 'spotify', 'file' => $this->history()])
            ->assertSessionHasNoErrors();
        $this->post(route('imports.store'), ['files' => [$this->history()]]);
        $running = ImportRun::query()->sole();
        $running->update(['status' => 'running']);
        $runningJob = DB::table('jobs')->where('queue', 'imports')->value('id');
        DB::table('jobs')->where('id', $runningJob)->update(['reserved_at' => time()]);
        $this->post(route('imports.store'), ['files' => [$this->history()]]);
        $queued = ImportRun::query()->where('status', 'queued')->sole();
        $queuedJob = DB::table('jobs')->where('id', '!=', $runningJob)->value('id');
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('imports.store'), ['files' => [$this->history()]]);
        $otherRunning = ImportRun::query()->where('user_id', $other->id)->sole();
        $otherRunning->update(['status' => 'running']);
        $otherJob = DB::table('jobs')->whereNotIn('id', [$runningJob, $queuedJob])->value('id');
        $unrelatedJob = DB::table('jobs')->insertGetId([
            'queue' => 'imports', 'payload' => json_encode(['displayName' => 'AnotherJob'], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
        $otherQueueJob = DB::table('jobs')->insertGetId([
            'queue' => 'default', 'payload' => DB::table('jobs')->where('id', $runningJob)->value('payload'),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
        $control = app(ImportControl::class);
        $control->markAbortable($running->id);
        $control->requestAbort($running->id, delete: true);
        Cache::store('file')->put('import-progress:'.$running->id, ['processed' => 100], 60);

        try {
            $this->artisan('imports:abort-running', ['--workers-stopped' => true])
                ->assertSuccessful()->expectsOutputToContain('Aborted 2 running imports');

            $this->assertSame('aborted', $running->fresh()->status);
            $this->assertSame('aborted', $otherRunning->fresh()->status);
            $this->assertSame('queued', $queued->fresh()->status);
            $this->assertDatabaseMissing('jobs', ['id' => $runningJob]);
            $this->assertDatabaseMissing('jobs', ['id' => $otherJob]);
            $this->assertDatabaseHas('jobs', ['id' => $queuedJob]);
            $this->assertDatabaseHas('jobs', ['id' => $unrelatedJob]);
            $this->assertDatabaseHas('jobs', ['id' => $otherQueueJob]);
            $this->assertDatabaseCount('listens', 1);
            Storage::disk('local')->assertExists($running->files[0]['path']);
            Storage::disk('local')->assertExists($otherRunning->files[0]['path']);
            $this->assertFalse($control->isAborting($running->id));
            $this->assertFalse($control->shouldDelete($running->id));
            $this->assertFalse($control->isAbortable($running->id));
            $this->assertFalse(Cache::store('file')->has('import-progress:'.$running->id));
            $this->artisan('imports:abort-running', ['--workers-stopped' => true])
                ->assertSuccessful()->expectsOutputToContain('Aborted 0 running imports');
            $this->actingAs($user)->post(route('imports.retry', $running->id))->assertRedirect();
            $this->assertSame('queued', $running->fresh()->status);
            $this->assertDatabaseCount('jobs', 4);
        } finally {
            $control->clearAbort($running->id);
            $control->clearAbortable($running->id);
            Cache::store('file')->forget('import-progress:'.$running->id);
        }
    }

    public function test_force_abort_rejects_an_unidentifiable_import_job_without_changing_the_import(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $run->update(['status' => 'running']);
        DB::table('jobs')->update(['payload' => json_encode([
            'displayName' => ProcessStreamingHistoryImport::class,
            'data' => ['commandName' => ProcessStreamingHistoryImport::class],
        ], JSON_THROW_ON_ERROR)]);

        try {
            $this->artisan('imports:abort-running', ['--workers-stopped' => true])->run();
            $this->fail('The command should reject an unidentifiable job.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertSame('An import job payload cannot be identified.', $exception->getMessage());
        }

        $this->assertSame('running', $run->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
        Storage::disk('local')->assertExists($run->files[0]['path']);
    }

    public function test_force_abort_rejects_a_non_database_import_queue(): void
    {
        $run = ImportRun::query()->create([
            'id' => (string) Str::uuid7(), 'user_id' => User::factory()->create()->id, 'status' => 'running', 'files' => [],
        ]);
        config(['queue.connections.imports.driver' => 'redis']);

        $this->artisan('imports:abort-running', ['--workers-stopped' => true])
            ->assertFailed()->expectsOutputToContain('requires the imports database queue');

        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_abort_does_not_target_a_different_or_unsupported_worker(): void
    {
        $user = User::factory()->create();
        $run = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'running', 'files' => []]);
        $other = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'running', 'files' => []]);
        $control = app(ImportControl::class);
        $control->markAbortable($run->id);

        try {
            $this->actingAs($user)->post(route('imports.abort', $other->id))->assertStatus(409);
            $this->post(route('imports.abort', $run->id))->assertRedirect();
            $this->assertFalse($control->isAborting($other->id));
            $run->update(['status' => 'completed']);
            $this->post(route('imports.abort', $run->id))->assertStatus(409);
        } finally {
            $control->clearAbort($run->id);
            $control->clearAbortable($run->id);
        }
    }

    public function test_completed_import_can_be_retried_without_duplicate_listens(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $job = new ProcessStreamingHistoryImport($run->id);
        app()->call([$job, 'handle']);

        $this->post(route('imports.retry', $run->id))->assertRedirect(route('imports.index', ['started' => 1]));
        $this->assertSame('queued', $run->refresh()->status);
        app()->call([$job, 'handle']);

        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(1, $run->result['duplicates']);
        $this->assertDatabaseCount('listens', 1);
        Queue::assertPushed(ProcessStreamingHistoryImport::class, 2);
    }

    public function test_failed_or_aborted_import_can_be_retried_and_duplicate_retry_is_rejected(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $run->update(['status' => 'failed', 'error' => 'Failure']);

        $this->post(route('imports.retry', $run->id))->assertRedirect();
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertNull($run->error);
        $this->post(route('imports.retry', $run->id))->assertStatus(409);
        $run->update(['status' => 'aborted']);
        $this->post(route('imports.retry', $run->id))->assertRedirect();
        Queue::assertPushed(ProcessStreamingHistoryImport::class, 3);
    }

    public function test_import_controls_are_private_and_reject_missing_originals(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $run = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $owner->id, 'status' => 'completed', 'files' => []]);

        $this->actingAs($other)->post(route('imports.abort', $run->id))->assertNotFound();
        $this->post(route('imports.retry', $run->id))->assertNotFound();
        $this->delete(route('imports.files.destroy', $run->id))->assertNotFound();
        $this->actingAs($owner)->post(route('imports.retry', $run->id))
            ->assertSessionHasErrors(['import' => 'The original files are unavailable. Upload them again to start a new import.']);
        Queue::assertNothingPushed();
    }

    public function test_retained_files_can_be_deleted_without_deleting_listens(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $this->delete(route('imports.files.destroy', $run->id))->assertStatus(409);
        app()->call([(new ProcessStreamingHistoryImport($run->id)), 'handle']);
        $path = $run->refresh()->files[0]['path'];

        $this->delete(route('imports.files.destroy', $run->id))->assertRedirect(route('imports.index'));
        $this->assertSame([], $run->refresh()->files);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('listens', 1);
        Queue::assertPushed(ProcessStreamingHistoryImport::class);
    }

    public function test_bulk_cancel_targets_only_owned_queued_and_supported_running_imports(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $queued = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'queued', 'files' => []]);
        $running = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'running', 'files' => []]);
        $legacy = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'running', 'files' => []]);
        $otherRun = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $other->id, 'status' => 'queued', 'files' => []]);
        $control = app(ImportControl::class);
        $control->markAbortable($running->id);
        Cache::store('file')->put('import-abortable:'.$legacy->id, true, 60);
        $control->requestAbort($running->id);
        Storage::disk('local')->put('imports/'.$queued->id.'/0', 'uploaded export');

        try {
            $this->actingAs($user)->post(route('imports.bulk.abort'))
                ->assertRedirect(route('listens.create', ['cancel_requested' => 2, 'cancel_unavailable' => 1]));
            $this->assertTrue($control->isAborting($queued->id));
            $this->assertTrue($control->isAborting($running->id));
            $this->assertTrue($control->shouldDelete($running->id));
            $this->assertFalse($control->isAborting($legacy->id));
            $this->assertFalse($control->isAborting($otherRun->id));
            $this->assertDatabaseCount('import_runs', 4);

            app()->call([(new ProcessStreamingHistoryImport($queued->id)), 'handle']);
            $this->assertDatabaseMissing('import_runs', ['id' => $queued->id]);
            Storage::disk('local')->assertMissing('imports/'.$queued->id.'/0');
        } finally {
            foreach ([$queued, $running, $legacy, $otherRun] as $run) {
                $control->clearAbort($run->id);
                $control->clearAbortable($run->id);
            }
        }
    }

    public function test_bulk_cancel_deletes_running_import_after_rolling_back_its_current_file(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history(), $this->history(150)]]);
        $run = ImportRun::query()->sole();
        $path = $run->files[0]['path'];
        $count = 0;
        DB::listen(function ($query) use (&$count, $run): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "listens"') && ++$count === 50) {
                app(ImportControl::class)->requestAbort($run->id, delete: true);
            }
        });

        app()->call([(new ProcessStreamingHistoryImport($run->id)), 'handle']);

        $this->assertDatabaseMissing('import_runs', ['id' => $run->id]);
        $this->assertDatabaseCount('listens', 1);
        Storage::disk('local')->assertMissing($path);
        $this->assertFalse(app(ImportControl::class)->isAborting($run->id));
        Queue::assertPushed(ProcessStreamingHistoryImport::class);
    }

    public function test_bulk_delete_removes_only_owned_finished_imports_and_retained_files(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('imports.store'), ['files' => [$this->history()]]);
        $completed = ImportRun::query()->sole();
        app()->call([(new ProcessStreamingHistoryImport($completed->id)), 'handle']);
        $path = $completed->refresh()->files[0]['path'];
        $failed = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'failed', 'files' => []]);
        $active = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'queued', 'files' => []]);
        $other = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => User::factory()->create()->id, 'status' => 'completed', 'files' => []]);

        $this->delete(route('imports.bulk.destroy'))->assertRedirect(route('listens.create', ['deleted_imports' => 2]));

        $this->assertDatabaseMissing('import_runs', ['id' => $completed->id]);
        $this->assertDatabaseMissing('import_runs', ['id' => $failed->id]);
        $this->assertDatabaseHas('import_runs', ['id' => $active->id]);
        $this->assertDatabaseHas('import_runs', ['id' => $other->id]);
        $this->assertDatabaseCount('listens', 1);
        Storage::disk('local')->assertMissing($path);
        Queue::assertPushed(ProcessStreamingHistoryImport::class);
    }

    public function test_bulk_cancel_is_honoured_if_the_queued_job_fails_before_it_starts(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $path = $run->files[0]['path'];
        $this->post(route('imports.bulk.abort'))->assertRedirect();

        (new ProcessStreamingHistoryImport($run->id))->failed(new \RuntimeException('Job could not start'));

        $this->assertDatabaseMissing('import_runs', ['id' => $run->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertFalse(app(ImportControl::class)->isAborting($run->id));
        Queue::assertPushed(ProcessStreamingHistoryImport::class);
    }

    public function test_real_queue_worker_removes_a_cancelled_queued_import(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post(route('imports.store'), ['files' => [$this->history()]]);
        $run = ImportRun::query()->sole();
        $path = $run->files[0]['path'];
        $this->post(route('imports.bulk.abort'))->assertRedirect();

        $this->artisan('queue:work', ['connection' => 'imports', '--queue' => 'imports', '--once' => true, '--timeout' => 7200])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('import_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('jobs', ['queue' => 'imports']);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('listens', 0);
    }

    public function test_bulk_import_controls_require_authentication(): void
    {
        $this->post(route('imports.bulk.abort'))->assertRedirect(route('login'));
        $this->delete(route('imports.bulk.destroy'))->assertRedirect(route('login'));
    }

    public function test_bulk_delete_reports_file_removal_failure_and_keeps_the_import_record(): void
    {
        $user = User::factory()->create();
        $run = ImportRun::query()->create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'status' => 'completed', 'files' => []]);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('deleteDirectory')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->actingAs($user)->delete(route('imports.bulk.destroy'))
            ->assertSessionHasErrors(['imports' => 'Some import files could not be deleted. Earlier imports may already have been deleted; please try again.']);

        $this->assertDatabaseHas('import_runs', ['id' => $run->id]);
    }
}
