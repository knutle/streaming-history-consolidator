import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as albumShow } from '@/routes/albums';
import { show as artistShow } from '@/routes/artists';
import { catalog, index, match, show } from '@/routes/listens';

type Listen = {
    id: number;
    track_name: string;
    artist_name: string;
    release_name: string | null;
    source: 'spotify' | 'apple';
    listened_at: number;
    played_ms: number;
    recording_mbid: string | null;
    track_metadata: {
        additional_info: {
            duration_ms?: number;
            artist_mbids?: string[];
            release_mbid?: string;
            isrcs?: string[];
            apple_music?: {
                catalog_id?: string;
                track_artist?: string;
                album_artist?: string;
                genre?: string;
                release_date?: string;
                track_number?: number;
                disc_number?: number;
                end_reason?: string;
                source_type?: string;
                offline?: string;
                shuffle?: string;
                repeat?: string;
                start_position_ms?: number;
                end_position_ms?: number;
            };
        };
    };
};

type Props = {
    listen: Listen;
    related: Listen[];
    musicbrainzEnabled: boolean;
    search: string;
    artistSearch: string;
    searchPage: number;
    hasMore: boolean;
    matchResult: number | null;
    catalogResult: number | null;
    musicBrainzSeed: string | null;
    catalogs: Record<
        string,
        {
            id: string;
            track_name: string;
            artist_name: string;
            release_name: string | null;
            duration_ms: number | null;
            isrc?: string | null;
            album_artist?: string;
            genre?: string;
            release_date?: string;
            track_number?: number;
            disc_number?: number;
            origin: 'import' | 'manual' | 'local';
        }
    >;
    matchedArtists: { mbid: string; name: string | null }[];
    matchedAlbums: { mbid: string; name: string }[];
    suggestions: {
        recording_mbid: string;
        track_name: string;
        artist_name: string;
    }[];
};

function date(timestamp: number) {
    return (
        new Date(timestamp * 1000)
            .toISOString()
            .replace('T', ' ')
            .slice(0, 19) + ' UTC'
    );
}

function catalogUrl(provider: 'apple' | 'spotify', id: string) {
    return provider === 'spotify'
        ? `https://open.spotify.com/track/${encodeURIComponent(id)}`
        : `https://music.apple.com/us/song/${encodeURIComponent(id)}`;
}

export default function ListenDetails({
    listen,
    related,
    musicbrainzEnabled,
    search,
    artistSearch,
    searchPage,
    hasMore,
    suggestions,
    matchResult,
    catalogResult,
    catalogs,
    musicBrainzSeed,
    matchedArtists,
    matchedAlbums,
}: Props) {
    const [selectedCatalog, setSelectedCatalog] = useState<'apple' | 'spotify'>(
        'apple',
    );
    const { errors } = usePage().props;
    const info = listen.track_metadata.additional_info;
    const apple = info.apple_music;
    const appleDetails = [
        { label: 'Track artist', value: apple?.track_artist },
        { label: 'Album artist', value: apple?.album_artist },
        { label: 'Genre', value: apple?.genre },
        { label: 'Release date', value: apple?.release_date },
        { label: 'Track number', value: apple?.track_number },
        { label: 'Disc number', value: apple?.disc_number },
        { label: 'End reason', value: apple?.end_reason },
        { label: 'Source type', value: apple?.source_type },
        { label: 'Offline', value: apple?.offline },
        { label: 'Shuffle', value: apple?.shuffle },
        { label: 'Repeat', value: apple?.repeat },
        { label: 'Start position (ms)', value: apple?.start_position_ms },
        { label: 'End position (ms)', value: apple?.end_position_ms },
    ].filter(({ value }) => value != null && value !== '');
    const musicSearch = encodeURIComponent(
        `${listen.track_name} ${listen.artist_name}`,
    );
    const catalogMatch = catalogs[selectedCatalog];
    const inputKey = `${listen.id}-${selectedCatalog}`;

    return (
        <>
            <Head title={listen.track_name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link href={index()} className="text-sm underline">
                    Back to listening history
                </Link>
                <div>
                    <h1 className="text-2xl font-semibold">
                        {listen.track_name}
                    </h1>
                    <p className="text-muted-foreground">
                        {matchedArtists.length === 1 ? (
                            <Link
                                className="underline"
                                href={artistShow(matchedArtists[0].mbid)}
                            >
                                {listen.artist_name}
                            </Link>
                        ) : (
                            listen.artist_name
                        )}
                        {listen.release_name && (
                            <>
                                {' · '}
                                {matchedAlbums.length === 1 ? (
                                    <Link
                                        className="underline"
                                        href={albumShow(matchedAlbums[0].mbid)}
                                    >
                                        {listen.release_name}
                                    </Link>
                                ) : (
                                    listen.release_name
                                )}
                            </>
                        )}
                    </p>
                    {matchedArtists.length > 1 && (
                        <div className="mt-2 flex flex-wrap gap-3 text-sm">
                            {matchedArtists.map((artist, index) => (
                                <Link
                                    key={artist.mbid}
                                    className="underline"
                                    href={artistShow(artist.mbid)}
                                >
                                    {artist.name ??
                                        `MusicBrainz artist ${index + 1}`}
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
                {matchResult !== null && (
                    <p role="status" className="text-sm">
                        Identified {matchResult} matching listens. This choice
                        will also be used for future imports.
                    </p>
                )}
                {catalogResult !== null && (
                    <p role="status" className="text-sm">
                        Catalog match saved for {catalogResult} listens.
                    </p>
                )}
                <section className="rounded-xl border p-5">
                    <h2 className="text-lg font-semibold">Listen details</h2>
                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">Played at</dt>
                            <dd>{date(listen.listened_at)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Played duration
                            </dt>
                            <dd>
                                {(listen.played_ms / 1000).toFixed(1)} seconds
                            </dd>
                        </div>
                        {info.duration_ms != null && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Track duration
                                </dt>
                                <dd>
                                    {(info.duration_ms / 1000).toFixed(1)}{' '}
                                    seconds
                                </dd>
                            </div>
                        )}
                        {info.isrcs && info.isrcs.length > 0 && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Recording ISRCs
                                </dt>
                                <dd>{info.isrcs.join(', ')}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-muted-foreground">Source</dt>
                            <dd>
                                {listen.source === 'spotify'
                                    ? 'Spotify'
                                    : 'Apple Music'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                MusicBrainz
                            </dt>
                            <dd>
                                {listen.recording_mbid
                                    ? 'Matched'
                                    : 'Unmatched'}
                            </dd>
                        </div>
                        {appleDetails.map(({ label, value }) => (
                            <div key={label}>
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd>{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <div className="mt-4 flex flex-wrap gap-4 text-sm">
                        {listen.recording_mbid && (
                            <a
                                className="underline"
                                target="_blank"
                                rel="noreferrer"
                                href={`https://musicbrainz.org/recording/${encodeURIComponent(listen.recording_mbid)}`}
                            >
                                MusicBrainz recording
                            </a>
                        )}
                        {info.release_mbid && (
                            <a
                                className="underline"
                                target="_blank"
                                rel="noreferrer"
                                href={`https://musicbrainz.org/release/${encodeURIComponent(info.release_mbid)}`}
                            >
                                MusicBrainz release
                            </a>
                        )}
                        {info.artist_mbids?.map((id) => (
                            <a
                                key={id}
                                className="underline"
                                target="_blank"
                                rel="noreferrer"
                                href={`https://musicbrainz.org/artist/${encodeURIComponent(id)}`}
                            >
                                MusicBrainz artist
                            </a>
                        ))}
                    </div>
                </section>
                <section className="rounded-xl border p-5">
                    <h2 className="text-lg font-semibold">Catalog matches</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Search a catalog, copy the track link, and confirm its
                        metadata below. Imported track identifiers are matched
                        locally; imports never contact external catalog APIs. An
                        unambiguous catalog identifier from your other listens
                        can also be linked through the same MusicBrainz
                        recording. Manual choices are remembered for the same
                        track, artist, and album. Catalog metadata is saved per
                        track link; recording ISRCs are listed separately.
                    </p>
                    <div className="mt-3 flex flex-wrap gap-4 text-sm">
                        {(['spotify', 'apple'] as const).map((provider) => {
                            const item = catalogs[provider];
                            const name =
                                provider === 'spotify'
                                    ? 'Spotify'
                                    : 'Apple Music';
                            return (
                                <div key={provider} className="space-y-1">
                                    <div className="flex gap-2">
                                        <a
                                            className="underline"
                                            target="_blank"
                                            rel="noreferrer"
                                            href={
                                                provider === 'spotify'
                                                    ? `https://open.spotify.com/search/results/${musicSearch}`
                                                    : `https://music.apple.com/us/search?term=${musicSearch}`
                                            }
                                        >
                                            Search {name}
                                        </a>
                                        {item && (
                                            <a
                                                className="underline"
                                                target="_blank"
                                                rel="noreferrer"
                                                href={catalogUrl(
                                                    provider,
                                                    item.id,
                                                )}
                                            >
                                                Matched {name} track
                                            </a>
                                        )}
                                    </div>
                                    {item && (
                                        <div className="text-muted-foreground">
                                            <p>
                                                {item.track_name} ·{' '}
                                                {item.artist_name}
                                                {item.release_name
                                                    ? ` · ${item.release_name}`
                                                    : ''}{' '}
                                                ({item.origin})
                                            </p>
                                            {item.isrc && (
                                                <p>ISRC: {item.isrc}</p>
                                            )}
                                            {item.album_artist && (
                                                <p>
                                                    Album artist:{' '}
                                                    {item.album_artist}
                                                </p>
                                            )}
                                            {item.genre && (
                                                <p>Genre: {item.genre}</p>
                                            )}
                                            {item.release_date && (
                                                <p>
                                                    Release date:{' '}
                                                    {item.release_date}
                                                </p>
                                            )}
                                            {item.track_number && (
                                                <p>
                                                    Track: {item.track_number}
                                                </p>
                                            )}
                                            {item.disc_number && (
                                                <p>Disc: {item.disc_number}</p>
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <Form
                        {...catalog.form(listen.id)}
                        className="mt-4 grid gap-3 sm:grid-cols-2"
                    >
                        {({ errors, processing, clearErrors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="catalog-provider">
                                        Catalog
                                    </Label>
                                    <select
                                        id="catalog-provider"
                                        name="provider"
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                        value={selectedCatalog}
                                        onChange={(event) => {
                                            setSelectedCatalog(
                                                event.target.value === 'spotify'
                                                    ? 'spotify'
                                                    : 'apple',
                                            );
                                            clearErrors();
                                        }}
                                    >
                                        <option value="apple">
                                            Apple Music
                                        </option>
                                        <option value="spotify">Spotify</option>
                                    </select>
                                    <InputError message={errors.provider} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="catalog-url">
                                        Track link
                                    </Label>
                                    <Input
                                        key={inputKey}
                                        id="catalog-url"
                                        name="catalog_url"
                                        defaultValue={
                                            catalogMatch
                                                ? catalogUrl(
                                                      selectedCatalog,
                                                      catalogMatch.id,
                                                  )
                                                : ''
                                        }
                                        required
                                        maxLength={2048}
                                    />
                                    <InputError message={errors.catalog_url} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="catalog-track">
                                        Catalog track name
                                    </Label>
                                    <Input
                                        key={inputKey}
                                        id="catalog-track"
                                        name="catalog_track"
                                        defaultValue={
                                            catalogMatch?.track_name ??
                                            listen.track_name
                                        }
                                        required
                                        maxLength={255}
                                    />
                                    <InputError
                                        message={errors.catalog_track}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="catalog-artist">
                                        Catalog artist
                                    </Label>
                                    <Input
                                        key={inputKey}
                                        id="catalog-artist"
                                        name="catalog_artist"
                                        defaultValue={
                                            catalogMatch?.artist_name ??
                                            (listen.artist_name ===
                                            'Unknown artist'
                                                ? ''
                                                : listen.artist_name)
                                        }
                                        required
                                        maxLength={255}
                                    />
                                    <InputError
                                        message={errors.catalog_artist}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="catalog-album">
                                        Catalog album
                                    </Label>
                                    <Input
                                        key={inputKey}
                                        id="catalog-album"
                                        name="catalog_album"
                                        defaultValue={
                                            catalogMatch
                                                ? (catalogMatch.release_name ??
                                                  '')
                                                : (listen.release_name ?? '')
                                        }
                                        maxLength={255}
                                    />
                                    <InputError
                                        message={errors.catalog_album}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="catalog-isrc">
                                        Catalog ISRC (optional)
                                    </Label>
                                    <Input
                                        key={inputKey}
                                        id="catalog-isrc"
                                        name="catalog_isrc"
                                        defaultValue={catalogMatch?.isrc ?? ''}
                                        maxLength={15}
                                        placeholder="USABC2412345"
                                    />
                                    <InputError message={errors.catalog_isrc} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="catalog-duration">
                                        Track duration (milliseconds)
                                    </Label>
                                    <Input
                                        key={inputKey}
                                        id="catalog-duration"
                                        name="catalog_duration"
                                        type="number"
                                        min={1}
                                        max={86400000}
                                        defaultValue={
                                            catalogMatch
                                                ? (catalogMatch.duration_ms ??
                                                  '')
                                                : (info.duration_ms ?? '')
                                        }
                                    />
                                    <InputError
                                        message={errors.catalog_duration}
                                    />
                                </div>
                                <div>
                                    <Button disabled={processing}>
                                        Save catalog match
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                    {(listen.artist_name === 'Unknown artist' ||
                        !listen.release_name) && (
                        <p className="mt-3 text-sm text-muted-foreground">
                            This listen lacks an artist or album, so this choice
                            will apply only to this listen.
                        </p>
                    )}
                </section>
                {listen.recording_mbid === null && (
                    <section className="rounded-xl border p-5">
                        <h2 className="text-lg font-semibold">
                            Identify this song
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Choose a recording to identify your unmatched
                            listens with the same track and artist, and remember
                            it for future imports. Existing matched listens are
                            not changed.
                        </p>
                        {musicBrainzSeed && (
                            <p className="mt-3 text-sm">
                                Missing from MusicBrainz?{' '}
                                <a
                                    className="underline"
                                    href={musicBrainzSeed}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Open a prefilled recording editor
                                </a>
                                . Review the metadata and submit using your
                                MusicBrainz account. Source links are included
                                in the edit note for verification.
                            </p>
                        )}
                        {!musicbrainzEnabled ? (
                            <p className="mt-3 text-sm">
                                MusicBrainz matching is disabled. Set up the
                                local database to search for recordings.
                            </p>
                        ) : (
                            <>
                                <Form
                                    {...show.form(listen.id)}
                                    className="mt-4 flex flex-wrap items-end gap-3"
                                >
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="recording-search">
                                            Recording name starts with
                                            (case-sensitive)
                                        </Label>
                                        <Input
                                            id="recording-search"
                                            name="q"
                                            defaultValue={search}
                                            maxLength={255}
                                            required
                                        />
                                    </div>
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="artist-search">
                                            Artist contains (optional)
                                        </Label>
                                        <Input
                                            id="artist-search"
                                            name="artist"
                                            defaultValue={artistSearch}
                                            maxLength={255}
                                        />
                                    </div>
                                    <Button>Search</Button>
                                </Form>
                                <InputError
                                    className="mt-2"
                                    message={
                                        errors.recording_mbid ??
                                        errors.track_name ??
                                        errors.artist_name ??
                                        errors.q ??
                                        errors.artist ??
                                        errors.page
                                    }
                                />
                                <h3 className="mt-5 font-medium">
                                    Suggested recordings
                                </h3>
                                {suggestions.length === 0 ? (
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        No recordings found. Try a shorter or
                                        different name.
                                    </p>
                                ) : (
                                    <ul className="mt-2 divide-y">
                                        {suggestions.map((item) => (
                                            <li
                                                key={`${item.recording_mbid}-${item.track_name}-${item.artist_name}`}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                                            >
                                                <div>
                                                    <p>
                                                        {item.track_name} ·{' '}
                                                        {item.artist_name}
                                                    </p>
                                                    <a
                                                        className="text-muted-foreground underline"
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        href={`https://musicbrainz.org/recording/${encodeURIComponent(item.recording_mbid)}`}
                                                    >
                                                        View recording on
                                                        MusicBrainz
                                                    </a>
                                                </div>
                                                <Form
                                                    {...match.form(listen.id)}
                                                >
                                                    {({ processing }) => (
                                                        <>
                                                            <input
                                                                type="hidden"
                                                                name="recording_mbid"
                                                                value={
                                                                    item.recording_mbid
                                                                }
                                                            />
                                                            <input
                                                                type="hidden"
                                                                name="track_name"
                                                                value={
                                                                    item.track_name
                                                                }
                                                            />
                                                            <input
                                                                type="hidden"
                                                                name="artist_name"
                                                                value={
                                                                    item.artist_name
                                                                }
                                                            />
                                                            <Button
                                                                type="submit"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Choose recording
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {(searchPage > 1 || hasMore) && (
                                    <nav
                                        aria-label="Recording search pages"
                                        className="mt-4 flex gap-4 text-sm"
                                    >
                                        {searchPage > 1 && (
                                            <Link
                                                className="underline"
                                                href={show(listen.id, {
                                                    query: {
                                                        q: search,
                                                        artist: artistSearch,
                                                        page: searchPage - 1,
                                                    },
                                                })}
                                            >
                                                Previous
                                            </Link>
                                        )}
                                        {hasMore && (
                                            <Link
                                                className="underline"
                                                href={show(listen.id, {
                                                    query: {
                                                        q: search,
                                                        artist: artistSearch,
                                                        page: searchPage + 1,
                                                    },
                                                })}
                                            >
                                                Next
                                            </Link>
                                        )}
                                    </nav>
                                )}
                            </>
                        )}
                    </section>
                )}
                <section className="rounded-xl border p-5">
                    <h2 className="text-lg font-semibold">Related listens</h2>
                    {related.length === 0 ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            No related listens found.
                        </p>
                    ) : (
                        <ul className="mt-3 divide-y">
                            {related.map((item) => (
                                <li key={item.id} className="py-2 text-sm">
                                    <Link
                                        className="underline"
                                        href={show(item.id)}
                                    >
                                        {item.track_name} · {item.artist_name} ·{' '}
                                        {date(item.listened_at)}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

ListenDetails.layout = {
    breadcrumbs: [{ title: 'Listening history', href: index() }],
};
