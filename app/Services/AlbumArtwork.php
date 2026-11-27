<?php

namespace App\Services;

use App\Models\Listen;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;

class AlbumArtwork
{
    private const MAX_BYTES = 2_000_000;

    public function __construct(private ManualMusicEntityMatcher $groups) {}

    public function releaseForListen(Listen $listen): ?string
    {
        $choice = DB::table('album_artwork_choices')
            ->where('user_id', $listen->user_id)->where('entity_key', $this->groups->key('album', $listen))
            ->value('release_mbid');
        if (is_string($choice)) {
            return $choice;
        }

        $ids = DB::table('listen_releases')->join('music_releases', 'music_releases.id', '=', 'listen_releases.music_release_id')
            ->where('listen_releases.listen_id', $listen->id)->pluck('music_releases.mbid')->all();
        $metadataId = $listen->track_metadata['additional_info']['release_mbid'] ?? null;
        if (is_string($metadataId) && Str::isUuid($metadataId)) {
            $ids[] = $metadataId;
        }
        $ids = array_values(array_unique(array_map('strtolower', $ids)));

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** @return array{status: string, mbid: ?string, url: ?string, error: ?string} */
    public function forListen(Listen $listen): array
    {
        return $this->cover(config('artwork.enabled') ? $this->releaseForListen($listen) : null);
    }

    /** @return array{status: string, mbid: ?string, url: ?string, error: ?string} */
    public function forGroup(Listen $listen): array
    {
        if (! config('artwork.enabled')) {
            return $this->cover(null);
        }
        $choice = DB::table('album_artwork_choices')->where('user_id', $listen->user_id)
            ->where('entity_key', $this->groups->key('album', $listen))->value('release_mbid');
        if (is_string($choice)) {
            return $this->cover($choice);
        }
        $related = $this->groups->related('album', $listen);
        $ids = DB::table('listen_releases')->join('music_releases', 'music_releases.id', '=', 'listen_releases.music_release_id')
            ->whereIn('listen_releases.listen_id', (clone $related)->select('listens.id'))
            ->distinct()->pluck('music_releases.mbid')->all();
        $metadata = (clone $related)->select('track_metadata->additional_info->release_mbid as release_mbid')->distinct()->get();
        foreach ($metadata as $item) {
            $id = $item->getAttribute('release_mbid');
            if (is_string($id) && Str::isUuid($id)) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique(array_map('strtolower', $ids)));

        return $this->cover(count($ids) === 1 ? $ids[0] : null);
    }

    /** @return array{status: string, mbid: ?string, url: ?string, error: ?string} */
    public function cover(?string $mbid): array
    {
        if (! config('artwork.enabled')) {
            return $this->result('disabled', null);
        }
        if ($mbid === null || ! Str::isUuid($mbid)) {
            return $this->result('unavailable', null);
        }
        $mbid = strtolower($mbid);

        try {
            $state = $this->cached($mbid);
            if ($state !== null) {
                return $state;
            }
            if (Cache::has('album-artwork:cooldown')) {
                return $this->unavailable($mbid);
            }

            return Cache::lock('album-artwork:lock:'.$mbid, 120)->block(1, function () use ($mbid): array {
                $state = $this->cached($mbid);
                if ($state !== null) {
                    return $state;
                }
                $bytes = $this->download($mbid);
                if ($bytes === null) {
                    if (Storage::disk('local')->exists($this->path($mbid))
                        && ! Storage::disk('local')->delete($this->path($mbid))) {
                        throw new \RuntimeException('Unable to remove a missing album cover.');
                    }

                    return $this->save($mbid, 'missing', 86400);
                }
                if (! Storage::disk('local')->put($this->path($mbid), $bytes)) {
                    throw new \RuntimeException('Unable to store album artwork.');
                }

                return $this->save($mbid, 'ready', 30 * 86400);
            });
        } catch (ConnectionException|FilesystemException|\RuntimeException|\InvalidArgumentException $exception) {
            report($exception);

            return $this->unavailable($mbid);
        } catch (LockTimeoutException) {
            return $this->result('error', $mbid, 'Artwork is being loaded. Please try again shortly.');
        }
    }

    public function path(string $mbid): string
    {
        return 'album-artwork/'.strtolower($mbid).'.image';
    }

    /** @return array{status: string, mbid: ?string, url: ?string, error: ?string} */
    private function unavailable(string $mbid): array
    {
        $stale = Storage::disk('local')->exists($this->path($mbid));

        return $this->save($mbid, $stale ? 'stale' : 'error', 300, 'Artwork is temporarily unavailable. Please try again later.');
    }

    /** @return array{status: string, mbid: ?string, url: ?string, error: ?string}|null */
    private function cached(string $mbid): ?array
    {
        $state = Cache::get('album-artwork:'.$mbid);
        if (! is_array($state) || ($state['expires'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }
        if (in_array($state['status'], ['ready', 'stale'], true) && ! Storage::disk('local')->exists($this->path($mbid))) {
            return null;
        }

        return $this->result($state['status'], $mbid, $state['error']);
    }

    /** @return array{status: string, mbid: ?string, url: ?string, error: ?string} */
    private function save(string $mbid, string $status, int $seconds, ?string $error = null): array
    {
        if (! Cache::put('album-artwork:'.$mbid, [
            'status' => $status, 'expires' => now()->getTimestamp() + $seconds, 'error' => $error,
        ], $seconds)) {
            throw new \RuntimeException('Unable to cache album artwork status.');
        }

        return $this->result($status, $mbid, $error);
    }

    /** @return array{status: string, mbid: ?string, url: ?string, error: ?string} */
    private function result(string $status, ?string $mbid, ?string $error = null): array
    {
        return ['status' => $status, 'mbid' => $mbid,
            'url' => in_array($status, ['ready', 'stale'], true) ? route('artwork.image', ['mbid' => $mbid], false) : null,
            'error' => $error];
    }

    private function download(string $mbid): ?string
    {
        $url = 'https://coverartarchive.org/release/'.$mbid.'/front-500';
        for ($hop = 0; $hop < 5; $hop++) {
            $host = parse_url($url, PHP_URL_HOST);
            if (! is_string($host) || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || (parse_url($url, PHP_URL_PORT) !== null && parse_url($url, PHP_URL_PORT) !== 443)
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
                || ! ($host === 'coverartarchive.org' || $host === 'archive.org' || str_ends_with($host, '.archive.org'))) {
                throw new \RuntimeException('Unsafe artwork redirect destination.');
            }
            $address = $this->publicAddress($host);
            $response = Http::withUserAgent('playweft/1.0 (https://github.com/knutle/playweft)')
                ->connectTimeout(3)->timeout(12)->withoutRedirecting()
                ->setHandler(new CurlHandler)
                ->withOptions(['proxy' => '', 'decode_content' => false, 'progress' => function (float $total, float $downloaded): void {
                    if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                        throw new \RuntimeException('Artwork exceeds the download size limit.');
                    }
                }, 'curl' => [
                    CURLOPT_RESOLVE => [$host.':443:'.$address],
                ]])->get($url);
            try {
                if ($response->redirect()) {
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));

                    continue;
                }
                if ($response->notFound()) {
                    return null;
                }
                if (in_array($response->status(), [429, 503], true)) {
                    $retryAfter = $response->header('Retry-After');
                    $delay = ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (int) strtotime($retryAfter) - now()->getTimestamp());
                    if (! Cache::put('album-artwork:cooldown', true, max(300, min(86400, $delay)))) {
                        throw new \RuntimeException('Unable to cache artwork provider cooldown.');
                    }
                    throw new \RuntimeException('Artwork provider is temporarily unavailable.');
                }
                if (! $response->successful()) {
                    throw new \RuntimeException('Artwork provider returned HTTP '.$response->status().'.');
                }
                if ((int) $response->header('Content-Length') > self::MAX_BYTES) {
                    throw new \RuntimeException('Artwork exceeds the download size limit.');
                }
                $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
                if (! in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    throw new \RuntimeException('Artwork provider returned an unsupported image type.');
                }
                $body = $response->toPsrResponse()->getBody();
                $bytes = '';
                while (! $body->eof()) {
                    $bytes .= $body->read(65536);
                    if (strlen($bytes) > self::MAX_BYTES) {
                        throw new \RuntimeException('Artwork exceeds the download size limit.');
                    }
                }
                $image = @getimagesizefromstring($bytes);
                if ($image === false || $image['mime'] !== $type || $image[0] > 4096 || $image[1] > 4096
                    || $image[0] * $image[1] > 16_000_000) {
                    throw new \RuntimeException('Artwork provider returned an invalid image.');
                }

                return $bytes;
            } finally {
                $response->close();
            }
        }

        throw new \RuntimeException('Too many artwork redirects.');
    }

    private function publicAddress(string $host): string
    {
        $addresses = $this->addresses($host);
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false
                || (ip2long($address) & 0xF0000000) === 0xE0000000) {
                throw new \RuntimeException('Artwork provider resolved to an unsafe address.');
            }
        }

        return $addresses[0];
    }

    /** @return non-empty-list<string> */
    protected function addresses(string $host): array
    {
        $addresses = gethostbynamel($host);
        if ($addresses === false || $addresses === []) {
            throw new \RuntimeException('Unable to resolve artwork provider.');
        }

        return $addresses;
    }
}
