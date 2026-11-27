# weftstream

A streaming history consolidator.

Bring Spotify and Apple Music listening history together in one place. Import your exports, browse a combined timeline, identify recordings with MusicBrainz, and link equivalent catalog tracks without merging their individual plays.

## What it does

- Imports Spotify Extended Streaming History JSON and Apple Music Play Activity CSV files, including supported export ZIPs.
- Helps identify recordings, artists, and albums using MusicBrainz. Review uncertain matches before applying them.
- Links Spotify and Apple Music catalog tracks that represent the same recording while keeping each listening event separate.
- Lets you filter your listening history, explore artist and album groups, and reuse saved matching choices.

You do not need to provide your Spotify or Apple Music password. Listening history is scoped to your account, and uploaded files are kept privately for retries until you delete them or your account. MusicBrainz API searches send the relevant music metadata to the configured API server when you explicitly request a search.

## Getting started

1. Request a Spotify Extended Streaming History export or an Apple Music Play Activity export.
2. Create an account, verify your email, and upload the supported files from the Imports page.
3. Follow import progress, then browse your listening history and review recording matches.

On a matched listen's detail page, use **Re-match recording** to search the local database or explicitly search the MusicBrainz API for a replacement. Confirming replaces the recording for your same-track/artist listens and linked catalog tracks, and remembers the choice for future imports. The current match remains until the queued replacement succeeds. If an affected listen, saved recording choice, or linked group conflicts, the entire replacement is rolled back and the detail page reports the conflict. Individual plays, imported names, catalog evidence, and separately saved artist/album choices are preserved.

On a matched artist's detail page, use **Re-match artist** and choose an exact imported artist-credit group. The replacement artist applies to that group and future imports. **Re-evaluate existing recordings** also queues checks of its existing recording matches; otherwise only unmatched listens are scanned. Local scans use the selected artist ID, while explicit API fallback sends the checked track and album names to MusicBrainz. Safe, unambiguous recording corrections also update matching plays and linked catalog tracks. Missing or ambiguous results, conflicting saved/linked choices, and recordings changed after queuing are retained and reported in scan progress. The artist association remains updated even when a recording cannot be replaced. A later artist choice cancels the superseded scan.

Re-imports skip previously imported events and can help identify unmatched listens. Spotify library or playlist JSON files can accompany history exports to help identify missing track URLs; Spotify's shorter `StreamingHistory_music` files are not supported.

## Local development

### Requirements

- PHP 8.5 and Composer
- Node.js 22 and npm
- Docker with Docker Compose (Laravel Sail)

### Setup

From the repository root:

```sh
cp .env.example .env
composer install
npm install
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

The local Sail stack provides the app, MySQL, Redis, Mailpit, and Selenium. The example configuration serves the app at [http://localhost:8000](http://localhost:8000).

Existing installations can set `APP_NAME=weftstream` in their environment for notifications and other server-side branding. Before changing it, explicitly set `SESSION_COOKIE`, `CACHE_PREFIX`, and `REDIS_PREFIX` to their existing effective values to preserve sessions, cached data, and Redis-backed queues. Run `php artisan config:clear` and gracefully restart queue workers after updating the environment; do not flush shared caches.

Start the frontend development server in a separate terminal:

```sh
npm run dev
```

Imports run on a dedicated queue. Start its worker in another terminal when developing or testing imports:

```sh
./vendor/bin/sail artisan queue:work imports --queue=imports --timeout=7200 --tries=1
```

Run the test suite with:

```sh
./vendor/bin/sail test
```

Stop the Sail services with `./vendor/bin/sail down`.

### MusicBrainz catalog data

Import-time automatic matching requires a locally configured MusicBrainz database. To set up the canonical catalog as a local SQLite database, run this from the host after installing the project dependencies:

```sh
php artisan musicbrainz:setup --download-canonical
```

The catalog download and indexing require substantial disk space and time. See `php artisan musicbrainz:setup --help` for the full mirror option, importing an existing canonical CSV, and storage requirements. Spotify API credentials are optional and are only used when manually requesting Spotify catalog metadata.

### Host your own MusicBrainz API mirror

The local database above is separate from the MusicBrainz web service. In particular, `musicbrainz:setup` uses **database-only** mode for its full mirror; neither that database nor the canonical SQLite catalog supplies an HTTP API or indexed API search.

Administrators can route all MusicBrainz API requests to a separately hosted, compatible mirror:

```dotenv
MUSICBRAINZ_API_BASE_URL=http://host.docker.internal:5000/ws/2
MUSICBRAINZ_API_MIN_INTERVAL=0
```

The base URL includes `/ws/2` (or your reverse proxy's equivalent API prefix). A trailing slash is optional. Only HTTP(S) URLs without embedded credentials, query strings, or fragments are accepted. HTTP is intended for trusted private networks; use HTTPS across untrusted networks. Authentication credentials for a reverse proxy are not managed by the application, and URLs are administrator-controlled, not user-supplied.

The interval is a finite, non-negative number of seconds within the supported timer range. Use zero only for a mirror you control and whose capacity permits it. Requests remain serialized across workers, and server `Retry-After` cooldowns still apply. The official public MusicBrainz hosts always require HTTPS and at least 1.1 seconds between requests, even if a smaller interval is configured. Each mirror has separate shared throttle/cooldown state.

If these settings are omitted, the application continues using `https://musicbrainz.org/ws/2` and a 1.1-second interval. A configured mirror is exclusive: connection failures, redirects, invalid responses, or missing search support are reported, **never silently retried against the public API**.

This setting covers recording, artist, and release searches/lookups, explicit bulk matching and artist scans, and artwork release searches. It does not enable API calls during automatic imports, replace the local matching database, redirect MusicBrainz website/editor links, or mirror Cover Art Archive images. Those images still come from Cover Art Archive/Internet Archive when artwork is enabled.

#### Run a separate searchable mirror

Use the official [metabrainz/musicbrainz-docker](https://github.com/metabrainz/musicbrainz-docker) project in a **separate directory and Docker Compose project**, not inside weftstream's existing database setup. Do not reuse or delete the application's existing database volumes.

Check the upstream requirements before provisioning. Upstream currently recommends x86-64, 16 CPU threads, 16 GB RAM, and 350 GB disk space for a mirror with indexed search, plus Docker Compose 2+, Git, and GNU Bash 4+ for its helper scripts. These are upstream guidance, not a guarantee of available capacity. macOS users should check their architecture and Docker resource allocation; the existing smaller database-only requirements do not cover a searchable API mirror.

The basic upstream sequence is:

```sh
git clone https://github.com/metabrainz/musicbrainz-docker.git
cd musicbrainz-docker
# Default to a host-only web-service binding; adjust for a trusted network if needed.
printf 'MUSICBRAINZ_DOCKER_HOST_IPADDRCOL=127.0.0.1:\n' >> .env
docker compose build
docker compose run --rm musicbrainz createdb.sh -fetch
docker compose up -d
docker compose exec indexer python -m sir reindex --entity-type artist --entity-type recording --entity-type release
```

Follow upstream prompts and data-use terms. **Do not select `alt-db-only-mirror`**: it omits the website and API. Starting the database and website is not sufficient; the search service needs populated artist, recording, and release indexes. Upstream also documents downloading prebuilt search indexes as an alternative to building them.

Only the mirror's web service needs to be reachable by weftstream. For a host-only client, set `MUSICBRAINZ_DOCKER_HOST_IPADDRCOL=127.0.0.1:` in the mirror project's `.env` to bind its default port 5000 to loopback. For an application on another machine or container network, configure a reachable private interface or trusted shared network instead. Keep PostgreSQL, Solr/search, and Valkey ports private; do not enable upstream's publishing-all-ports override. Consult upstream security notes before exposing any service.

Configure upstream's `MUSICBRAINZ_WEB_SERVER_HOST` and `MUSICBRAINZ_WEB_SERVER_PORT` for the address used by your clients; these are separate from the port binding. The application does not follow redirects, so its API base must point directly to the final scheme, host, and API path. HTTPS mirrors need a certificate trusted by the application.

#### Connect from Sail and check readiness

Inside Sail, `localhost` refers to the application container, not the host or mirror container. On Docker Desktop, `host.docker.internal:5000` can reach a host-published web service, provided its bind address and firewall permit it. On Linux, configure the appropriate host gateway/private interface, or connect only the mirror web service to a trusted shared Docker network with an explicit hostname/alias. Do not expose backend services just to make API requests work.

Before changing the application's environment, verify search from the same network context as the application. For example, with a web service reachable through Docker Desktop:

```sh
./vendor/bin/sail exec -T laravel.test curl --fail --get \
  http://host.docker.internal:5000/ws/2/recording \
  --data-urlencode 'query=recording:"Example"' \
  --data-urlencode 'fmt=json' \
  --data-urlencode 'limit=1'
```

Repeat with `artist`/`artist:"Example"` and `release`/`release:"Example"`. A working search returns JSON containing a numeric `count` and the corresponding result list; an empty list is valid for an unmatched sample query. Also check a returned recording MBID using `/ws/2/recording/<MBID>?fmt=json&inc=artist-credits+releases`. A working website or ID lookup alone does not prove that indexed search is ready.

Set the application's `MUSICBRAINZ_API_BASE_URL` and interval only after those checks succeed. Run `./vendor/bin/sail artisan config:clear` for local development (or rebuild the configuration cache in production), then gracefully stop and restart the dedicated imports worker using the command above after active work finishes. All web instances and workers should use the same endpoint configuration and shared cache. Do not flush a shared cache when changing servers.

#### Keep the mirror current

A dump import is a snapshot. Database replication is optional and requires the upstream MetaBrainz token setup and applicable [Live Data Feed terms](https://musicbrainz.org/doc/Live_Data_Feed), including commercial-use considerations. Keep replication tokens in the separately managed mirror's secrets, never in weftstream source or request URLs.

Database replication does not automatically maintain search indexes. Schedule appropriate index rebuilds using upstream instructions; do not assume search results are current just because replication succeeds. Upstream labels live search indexing experimental, so it is not part of this default setup. Review upstream update/maintenance instructions and keep backups before upgrading or replacing mirror data.

### Optional album artwork

Listen and artist/album details use compact, themed summaries with expandable playback metadata. Album covers are optional: set `ALBUM_ARTWORK_ENABLED=true` to enable them app-wide (disabled by default).

When enabled, opening an album or listen detail page can send an existing, unambiguous release ID to [Cover Art Archive](https://musicbrainz.org/doc/Cover_Art_Archive/API). Covers load separately from the page, are downloaded through Cover Art Archive/Internet Archive, and are cached privately under `storage/app/private/album-artwork`. The browser receives images from this application, not from a third-party image host. A missing or ambiguous release keeps a placeholder; artist pages do not fetch artist photos.

“Find or change album artwork” searches MusicBrainz only when you submit the search form. This sends your entered album search text to the configured MusicBrainz API server. Selecting a release saves an artwork-only choice for your account's exact imported artist/album group; it does not change recording matches, album associations, catalog links, or individual plays. Resetting the choice returns to known-release artwork.

Successful covers are refreshed on access after 30 days; missing covers are cached for 24 hours. Temporary errors use a short backoff (at least five minutes, respecting provider cooldowns up to 24 hours), remain visible as errors rather than “no cover”, and retain a previously cached image when possible. Files are replaced on refresh; the cache directory needs writable, persistent storage, shared between web instances when running more than one. Use the application's shared cache store for locks and status metadata. No extra queue worker is required.

After updating an existing installation, run `./vendor/bin/sail artisan migrate` and `npm run build`. If configuration is cached, rebuild it after changing the flag with `./vendor/bin/sail artisan config:cache`. Disabling the flag stops artwork lookups, search/selection, and cached-image serving without deleting saved choices or files.
