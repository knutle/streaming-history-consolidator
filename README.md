# playweft

A streaming history consolidator.

Bring Spotify and Apple Music listening history together in one place. Import your exports, browse a combined timeline, identify recordings with MusicBrainz, and link equivalent catalog tracks without merging their individual plays.

## What it does

- Imports Spotify Extended Streaming History JSON and Apple Music Play Activity CSV files, including supported export ZIPs.
- Helps identify recordings, artists, and albums using MusicBrainz. Review uncertain matches before applying them.
- Links Spotify and Apple Music catalog tracks that represent the same recording while keeping each listening event separate.
- Lets you filter your listening history, explore artist and album groups, and reuse saved matching choices.

You do not need to provide your Spotify or Apple Music password. Listening history is scoped to your account, and uploaded files are kept privately for retries until you delete them or your account. MusicBrainz searches send the relevant music metadata when you explicitly request a search.

## Getting started

1. Request a Spotify Extended Streaming History export or an Apple Music Play Activity export.
2. Create an account, verify your email, and upload the supported files from the Imports page.
3. Follow import progress, then browse your listening history and review recording matches.

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

Existing installations can set `APP_NAME=playweft` in their environment for notifications and other server-side branding. Changing it may also change default session cookie and cache prefixes.

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
