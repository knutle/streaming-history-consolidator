<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SpotifyCatalogLookup
{
    /**
     * @return array{track_name: string, artist_name: string, release_name: ?string, duration_ms: ?int, isrc: ?string, url: string}
     */
    public function track(string $id): array
    {
        $clientId = config('services.spotify.client_id');
        $secret = config('services.spotify.client_secret');
        $market = config('services.spotify.market');
        if (! is_string($clientId) || trim($clientId) === '' || ! is_string($secret) || trim($secret) === '') {
            $this->fail('Spotify lookup is not configured. Set SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET on the server.');
        }
        if (! is_string($market) || ! preg_match('/^[A-Z]{2}$/D', $market)) {
            $this->fail('Spotify lookup requires a valid two-letter SPOTIFY_MARKET country code.');
        }

        try {
            $tokenResponse = Http::asForm()->withBasicAuth($clientId, $secret)
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://accounts.spotify.com/api/token', ['grant_type' => 'client_credentials']);
            $this->checkResponse($tokenResponse, true);
            $token = $tokenResponse->json('access_token');
            if (! is_string($token) || $token === '') {
                $this->fail('Spotify returned an invalid authorization response.');
            }

            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->get('https://api.spotify.com/v1/tracks/'.$id, ['market' => $market]);
            $this->checkResponse($response, false);
        } catch (ConnectionException) {
            $this->fail('Could not connect to Spotify. Please try again.');
        }

        $data = $response->json();
        if (! is_array($data) || ($data['id'] ?? null) !== $id) {
            $this->fail('Spotify returned a different or invalid track for the configured market. Try another market.');
        }
        if (($data['is_playable'] ?? true) === false) {
            $this->fail('This track is not available in the configured Spotify market.');
        }
        if ((isset($data['album']) && ! is_array($data['album']))
            || (isset($data['external_ids']) && ! is_array($data['external_ids']))
            || (isset($data['is_playable']) && ! is_bool($data['is_playable']))) {
            $this->fail('Spotify returned invalid track metadata.');
        }
        $track = $data['name'] ?? null;
        $artists = $data['artists'] ?? null;
        $album = $data['album']['name'] ?? null;
        $duration = $data['duration_ms'] ?? null;
        $rawIsrc = $data['external_ids']['isrc'] ?? null;
        $isrc = $rawIsrc === null ? null : Isrc::normalize($rawIsrc);
        if (! is_array($artists) || $artists === []) {
            $this->fail('Spotify returned incomplete track metadata.');
        }
        $names = [];
        foreach ($artists as $artist) {
            $name = $artist['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                $this->fail('Spotify returned incomplete track metadata.');
            }
            $names[] = $name;
        }
        $artist = implode(', ', $names);
        if (! is_string($track) || trim($track) === '' || mb_strlen($track) > 255 || mb_strlen($artist) > 255
            || ($album !== null && (! is_string($album) || mb_strlen($album) > 255))
            || ($duration !== null && (! is_int($duration) || $duration < 1 || $duration > 86400000))
            || ($rawIsrc !== null && $isrc === null)) {
            $this->fail('Spotify returned invalid track metadata.');
        }

        return ['track_name' => $track, 'artist_name' => $artist, 'release_name' => $album, 'duration_ms' => $duration, 'isrc' => $isrc,
            'url' => 'https://open.spotify.com/track/'.$id];
    }

    private function checkResponse(Response $response, bool $authorization): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->status() === 429) {
            $this->fail('Spotify rate limit or quota reached. Please try again later.');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            $this->fail('Spotify denied access. Check the app credentials and Spotify developer access.');
        }
        if (! $authorization && $response->notFound()) {
            $this->fail('Spotify could not find this track in the configured market.');
        }
        $this->fail('Spotify could not '.($authorization ? 'authorize this lookup' : 'retrieve this track').'. Please try again.');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['catalog_url' => $message]);
    }
}
