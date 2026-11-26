<?php

namespace Tests\Feature;

use App\Models\ImportRun;
use App\Models\User;
use App\Services\ImportControl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ImportProgressConcurrencyTest extends TestCase
{
    public function test_progress_is_readable_while_sqlite_has_an_active_writer(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'import-test-');
        $this->assertNotFalse($database);
        $id = (string) Str::uuid7();
        $worker = null;
        $input = new InputStream;

        try {
            $connection = config('database.connections.sqlite');
            $connection['database'] = $database;
            $connection['busy_timeout'] = 300;
            config([
                'database.connections.import_test' => $connection,
                'database.default' => 'import_test',
                'session.driver' => 'database',
                'session.connection' => 'import_test',
                'session.lottery' => [0, 100],
            ]);
            app('session')->forgetDrivers();
            $this->artisan('migrate', ['--database' => 'import_test', '--force' => true])->assertExitCode(0);

            $user = User::factory()->create();
            ImportRun::query()->create(['id' => $id, 'user_id' => $user->id, 'status' => 'running', 'files' => []]);
            app(ImportControl::class)->markAbortable($id);
            Cache::store('file')->put('import-progress:'.$id, ['file' => 'history.json', 'current' => 1, 'total' => 1, 'processed' => 100], 60);
            $session = app('session')->driver();
            $session->start();
            $session->save();
            $this->withCookie($session->getName(), $session->getId())->actingAs($user);

            $worker = new Process([PHP_BINARY, '-r', <<<'PHP'
                $db = new PDO('sqlite:'.$argv[1]);
                $db->exec('BEGIN IMMEDIATE');
                $db->exec('UPDATE users SET name = name');
                echo "locked\n";
                fgets(STDIN);
                $db->exec('ROLLBACK');
                PHP, $database]);
            $worker->setInput($input);
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'locked')));

            $this->get(route('imports.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('imports.data.0.progress.processed', 100));
            $this->get(route('listens.create'))->assertOk();
            $this->post(route('imports.bulk.abort'))
                ->assertRedirect(route('listens.create', ['cancel_requested' => 1, 'cancel_unavailable' => 0]));
            $this->assertTrue(app(ImportControl::class)->isAborting($id));
            $this->get(route('listens.create', ['cancel_requested' => 1, 'cancel_unavailable' => 0]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('cancelRequested', 1)->where('cancelUnavailable', 0));
            $this->assertTrue($worker->isRunning());
        } finally {
            if ($worker !== null && $worker->isRunning()) {
                $input->write("done\n");
                $input->close();
                $worker->wait();
            }
            Cache::store('file')->forget('import-progress:'.$id);
            app(ImportControl::class)->clearAbort($id);
            app(ImportControl::class)->clearAbortable($id);
            DB::purge('import_test');
            unlink($database);
        }
    }
}
