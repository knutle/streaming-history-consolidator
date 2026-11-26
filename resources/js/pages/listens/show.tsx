import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, match, show } from '@/routes/listens';

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
}: Props) {
    const { errors } = usePage().props;
    const info = listen.track_metadata.additional_info;
    const musicSearch = encodeURIComponent(
        `${listen.track_name} ${listen.artist_name}`,
    );

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
                        {listen.artist_name}
                        {listen.release_name ? ` · ${listen.release_name}` : ''}
                    </p>
                </div>
                {matchResult !== null && (
                    <p role="status" className="text-sm">
                        Identified {matchResult} matching listens. This choice
                        will also be used for future imports.
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
                        <a
                            className="underline"
                            target="_blank"
                            rel="noreferrer"
                            href={
                                listen.source === 'spotify'
                                    ? `https://open.spotify.com/search/${musicSearch}`
                                    : `https://music.apple.com/us/search?term=${musicSearch}`
                            }
                        >
                            Search{' '}
                            {listen.source === 'spotify'
                                ? 'Spotify'
                                : 'Apple Music'}
                        </a>
                    </div>
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
