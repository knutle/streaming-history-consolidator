<?php

namespace Tests\Feature;

use App\Models\Listen;
use App\Models\User;
use App\Services\CatalogMatcher;
use App\Services\ManualListenMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogRecordingGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicbrainz.enabled' => false]);
    }

    private function listen(User $user, string $source, string $id, string $title, int $time, ?string $recording = null): Listen
    {
        $catalog = app(CatalogMatcher::class)->match($source, $id, $title, 'Artist', 'Album', 180000, 'import');

        return Listen::query()->create([
            'user_id' => $user->id, 'source' => $source, 'fingerprint' => hash('sha256', "$source:$id:$time"),
            'listened_at' => $time, 'track_name' => $title, 'artist_name' => 'Artist', 'release_name' => 'Album',
            'played_ms' => 60000, 'recording_mbid' => $recording,
            'track_metadata' => [
                'track_name' => $title, 'artist_name' => 'Artist', 'release_name' => 'Album',
                'additional_info' => ['catalogs' => [$source => $catalog]],
            ],
        ]);
    }

    public function test_linking_keeps_plays_and_all_catalog_sources_in_editor_evidence(): void
    {
        $user = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'Track', 1);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Track', 2);
        $spotify2 = $this->listen($user, 'spotify', 'ABCDEFGHIJKLMNOPQRSTUV', 'Track variation', 3);

        $this->actingAs($user)->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $spotify2->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('listens', 3);
        $this->get(route('listens.show', ['listen' => $apple->id, 'merge_q' => 'variation']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('linkedCatalogs', 3)->has('mergeCandidates', 1)
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return ! isset($query['edit-recording_name'])
                    && str_contains($query['edit-recording_edit_note'], 'music.apple.com/us/song/123')
                    && str_contains($query['edit-recording_edit_note'], 'open.spotify.com/track/0123456789ABCDEFGHIJKL')
                    && str_contains($query['edit-recording_edit_note'], 'open.spotify.com/track/ABCDEFGHIJKLMNOPQRSTUV')
                    && str_contains($query['edit-recording_edit_note'], 'Track variation');
            }));
        $this->get(route('music-entities.manage', ['type' => 'album', 'listen' => $apple->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed.fields.edit_note', fn ($note) => str_contains($note, 'Linked track to verify: https://open.spotify.com/track/ABCDEFGHIJKLMNOPQRSTUV')));
    }

    public function test_link_validation_and_ownership_prevent_conflicting_or_unauthorized_changes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $apple = $this->listen($owner, 'apple', '123', 'Track', 1, '11111111-1111-4111-8111-111111111111');
        $spotify = $this->listen($owner, 'spotify', '0123456789ABCDEFGHIJKL', 'Track', 2, '22222222-2222-4222-8222-222222222222');
        $foreign = $this->listen($other, 'spotify', 'ABCDEFGHIJKLMNOPQRSTUV', 'Track', 3);
        $same = $this->listen($owner, 'apple', '456', 'Track', 4);

        $this->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertRedirect(route('login'));
        $this->actingAs($owner)->post(route('listens.merge', $apple->id), ['other_listen' => $foreign->id])->assertNotFound();
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $same->id])->assertSessionHasErrors('merge');
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasErrors('merge');
        $this->assertDatabaseCount('catalog_recording_groups', 0);
    }

    public function test_existing_groups_merge_transitively_and_repeated_links_are_rejected(): void
    {
        $user = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'One', 1);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Two', 2);
        $apple2 = $this->listen($user, 'apple', '456', 'Three', 3);
        $spotify2 = $this->listen($user, 'spotify', 'ABCDEFGHIJKLMNOPQRSTUV', 'Four', 4);
        $this->actingAs($user)->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple2->id), ['other_listen' => $spotify2->id])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $spotify2->id])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple2->id), ['other_listen' => $spotify->id])->assertSessionHasErrors('merge');
        $this->assertDatabaseCount('catalog_recording_groups', 1);
        $this->get(route('listens.show', $apple2->id))->assertInertia(fn (Assert $page) => $page->has('linkedCatalogs', 4));
    }

    public function test_recording_editor_prefills_when_every_linked_title_agrees(): void
    {
        $user = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'Track', 1);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Track', 2);
        $other = $this->listen($user, 'spotify', 'ABCDEFGHIJKLMNOPQRSTUV', 'Track', 3);
        $this->actingAs($user)->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $other->id])->assertSessionHasNoErrors();
        $this->get(route('listens.show', $apple->id))->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query['edit-recording_name'] === 'Track'
                    && $query['edit-recording_artist_credit_names_0_name'] === 'Artist'
                    && str_contains($query['edit-recording_edit_note'], 'open.spotify.com/track/ABCDEFGHIJKLMNOPQRSTUV');
            }));
    }

    public function test_manual_same_provider_match_does_not_replace_native_group_identity(): void
    {
        $user = User::factory()->create();
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Track', 1);
        $apple = $this->listen($user, 'apple', '123', 'Variant', 2);
        $this->actingAs($user)->post(route('listens.catalog', $spotify->id), [
            'provider' => 'spotify', 'catalog_url' => 'spotify:track:ABCDEFGHIJKLMNOPQRSTUV',
            'catalog_track' => 'Other catalog match', 'catalog_artist' => 'Artist', 'catalog_album' => 'Album',
        ])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $spotify->id), ['other_listen' => $apple->id])->assertSessionHasNoErrors();
        $this->get(route('listens.show', $apple->id))->assertInertia(fn (Assert $page) => $page
            ->where('linkedCatalogs.0.catalog.id', '0123456789ABCDEFGHIJKL'));
    }

    public function test_linking_collects_deduplicated_catalog_matches_from_every_play_of_each_member(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'Apple title', 1);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Spotify title', 2);
        $anotherPlay = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Other spelling', 3);
        $foreign = $this->listen($other, 'spotify', '0123456789ABCDEFGHIJKL', 'Private', 4);
        $this->actingAs($user)->post(route('listens.catalog', $anotherPlay->id), [
            'provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/song/456',
            'catalog_track' => 'Additional title', 'catalog_artist' => 'Artist', 'catalog_album' => 'Other album',
            'catalog_isrc' => 'USABC2412345',
        ])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();

        foreach ([$apple, $spotify, $anotherPlay] as $listen) {
            $this->get(route('listens.show', $listen->id))->assertInertia(fn (Assert $page) => $page
                ->has('linkedEvidence', 3)
                ->where('linkedEvidence.2.id', '456')
                ->where('linkedEvidence.2.track_name', 'Additional title')
                ->where('linkedEvidence.2.isrc', 'USABC2412345')
                ->where('musicBrainzSeed', fn ($url) => str_contains($url, 'music.apple.com%2Fus%2Fsong%2F456')));
        }
        $this->assertDatabaseCount('listens', 4);
        $this->assertArrayNotHasKey('apple', $foreign->fresh()->track_metadata['additional_info']['catalogs']);
        $this->assertSame(1, DB::table('catalog_track_metadata')->where('user_id', $user->id)->where('catalog_id', '456')->count());
    }

    public function test_catalog_match_added_after_linking_is_visible_to_all_members(): void
    {
        $user = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'Apple title', 1);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Spotify title', 2);
        $this->actingAs($user)->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        $this->post(route('listens.catalog', $spotify->id), [
            'provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/song/456',
            'catalog_track' => 'Additional title', 'catalog_artist' => 'Artist', 'catalog_album' => 'Album',
        ])->assertSessionHasNoErrors();
        $this->get(route('listens.show', $apple->id))->assertInertia(fn (Assert $page) => $page
            ->has('linkedEvidence', 3)->where('linkedEvidence.2.id', '456'));
    }

    public function test_existing_groups_can_be_backfilled_without_duplicating_catalog_metadata(): void
    {
        $user = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'Apple title', 1);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Spotify title', 2);
        $this->actingAs($user)->post(route('listens.catalog', $spotify->id), [
            'provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/song/456',
            'catalog_track' => 'Additional title', 'catalog_artist' => 'Artist', 'catalog_album' => 'Album',
        ])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        foreach (DB::table('catalog_recording_members')->where('user_id', $user->id)->get() as $member) {
            $catalog = json_decode($member->catalog, true, 512, JSON_THROW_ON_ERROR);
            unset($catalog['related_catalogs']);
            DB::table('catalog_recording_members')->where('id', $member->id)->update(['catalog' => json_encode($catalog, JSON_THROW_ON_ERROR)]);
        }

        $this->artisan('catalogs:refresh-linked-evidence', ['--user' => (string) $user->id])->assertSuccessful();
        $this->artisan('catalogs:refresh-linked-evidence', ['--user' => (string) $user->id])->assertSuccessful();
        $this->get(route('listens.show', $apple->id))->assertInertia(fn (Assert $page) => $page
            ->has('linkedEvidence', 3)->where('linkedEvidence.2.id', '456'));
        $this->assertSame(1, DB::table('catalog_track_metadata')->where('user_id', $user->id)->where('catalog_id', '456')->count());
    }

    public function test_new_import_adds_remembered_catalog_evidence_to_the_linked_group(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'Apple title', 1);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Spotify title', 2);
        $other = $this->listen($user, 'spotify', 'ABCDEFGHIJKLMNOPQRSTUV', 'New variant', 3);
        $this->actingAs($user)->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        $this->post(route('listens.catalog', $other->id), [
            'provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/song/456',
            'catalog_track' => 'Additional title', 'catalog_artist' => 'Artist', 'catalog_album' => 'Album',
        ])->assertSessionHasNoErrors();
        $row = ['ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000,
            'master_metadata_track_name' => 'New variant', 'master_metadata_album_artist_name' => 'Artist',
            'master_metadata_album_album_name' => 'Album', 'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL'];

        $this->post(route('listens.import'), ['source' => 'spotify',
            'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([$row], JSON_THROW_ON_ERROR))])
            ->assertSessionHasNoErrors();
        $this->get(route('listens.show', $apple->id))->assertInertia(fn (Assert $page) => $page
            ->has('linkedEvidence', 3)->where('linkedEvidence.2.id', '456'));
        Http::assertNothingSent();
    }

    public function test_unlinking_removes_only_that_members_catalog_evidence(): void
    {
        $user = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'Apple title', 1);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Spotify title', 2);
        $other = $this->listen($user, 'spotify', 'ABCDEFGHIJKLMNOPQRSTUV', 'Different title', 3);
        $this->actingAs($user)->post(route('listens.catalog', $spotify->id), [
            'provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/song/456',
            'catalog_track' => 'Additional title', 'catalog_artist' => 'Artist', 'catalog_album' => 'Album',
        ])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        $this->post(route('listens.merge', $apple->id), ['other_listen' => $other->id])->assertSessionHasNoErrors();
        $member = DB::table('catalog_recording_members')->where('user_id', $user->id)
            ->where('catalog_key', hash('sha256', 'spotify:0123456789ABCDEFGHIJKL'))->value('id');

        $this->delete(route('listens.unlink', ['listen' => $apple->id, 'member' => $member]))->assertSessionHasNoErrors();
        $this->get(route('listens.show', $apple->id))->assertInertia(fn (Assert $page) => $page->has('linkedEvidence', 2));
        $this->assertSame('456', $spotify->fresh()->track_metadata['additional_info']['catalogs']['apple']['id']);
    }

    public function test_linked_recording_is_propagated_to_existing_and_future_plays_and_unlink_does_not_undo_it(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $mbid = '11111111-1111-4111-8111-111111111111';
        $apple = $this->listen($user, 'apple', '123', 'Original title', 1, $mbid);
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Variant', 2);
        $this->actingAs($user)->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        $this->assertSame($mbid, $spotify->fresh()->recording_mbid);

        $row = ['ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000, 'master_metadata_track_name' => 'Variant',
            'master_metadata_album_artist_name' => 'Artist', 'master_metadata_album_album_name' => 'Album',
            'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL'];
        $import = fn () => $this->post(route('listens.import'), ['source' => 'spotify',
            'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([$row], JSON_THROW_ON_ERROR))]);
        $import()->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 1]);
        $newListen = Listen::query()->latest('id')->firstOrFail();
        $metadata = $newListen->track_metadata;
        unset($metadata['additional_info']['recording_mbid'], $metadata['additional_info']['catalogs']);
        $newListen->update(['recording_mbid' => null, 'track_metadata' => $metadata]);
        $import()->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0]);
        $this->assertSame($mbid, $newListen->fresh()->recording_mbid);

        $member = DB::table('catalog_recording_members')->where('user_id', $user->id)->orderBy('id')->value('id');
        $this->actingAs(User::factory()->create())->delete(route('listens.unlink', ['listen' => $apple->id, 'member' => $member]))->assertNotFound();
        $this->actingAs($user)->delete(route('listens.unlink', ['listen' => $apple->id, 'member' => $member]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('catalog_recording_groups', 0);
        $this->assertSame($mbid, $spotify->fresh()->recording_mbid);
        Http::assertNothingSent();
    }

    public function test_conflicting_saved_recording_aborts_import_instead_of_overriding_group(): void
    {
        $user = User::factory()->create();
        $apple = $this->listen($user, 'apple', '123', 'Original', 1, '11111111-1111-4111-8111-111111111111');
        $spotify = $this->listen($user, 'spotify', '0123456789ABCDEFGHIJKL', 'Variant', 2);
        $this->actingAs($user)->post(route('listens.merge', $apple->id), ['other_listen' => $spotify->id])->assertSessionHasNoErrors();
        DB::table('listen_matches')->insert([
            'user_id' => $user->id, 'song_key' => app(ManualListenMatcher::class)->key('Variant', 'Artist'),
            'match' => json_encode(['recording_mbid' => '22222222-2222-4222-8222-222222222222', 'artist_name' => 'Artist',
                'artist_mbids' => [], 'release_mbid' => null], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = ['ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000, 'master_metadata_track_name' => 'Variant',
            'master_metadata_album_artist_name' => 'Artist', 'master_metadata_album_album_name' => 'Album',
            'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL'];
        $this->post(route('listens.import'), ['source' => 'spotify',
            'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([$row], JSON_THROW_ON_ERROR))])
            ->assertSessionHasErrors('file');
        $this->assertDatabaseCount('listens', 2);
    }
}
