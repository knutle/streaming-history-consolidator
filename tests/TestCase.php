<?php

namespace Tests;

use App\Jobs\ProcessRecordingAssociation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function processRecordingAssociation(): void
    {
        $id = DB::table('recording_association_runs')->where('status', 'queued')->orderByDesc('id')->value('id');
        $this->assertNotNull($id);
        app()->call([new ProcessRecordingAssociation($id), 'handle']);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
