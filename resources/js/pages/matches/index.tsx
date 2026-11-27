import { Head, Link } from '@inertiajs/react';
import Form from '@/components/form';
import { Button } from '@/components/ui/button';
import { show as listenShow } from '@/routes/listens';
import { destroy, edit, index } from '@/routes/matches';

type SavedMatch = {
    id: number;
    source_track: string | null;
    source_artist: string | null;
    source_album: string | null;
    listen_id: number | null;
    match: {
        recording_mbid?: string;
        recording_name?: string | null;
        artist_name?: string;
        provider?: 'spotify' | 'apple';
        id?: string;
        track_name?: string;
        release_name?: string | null;
    };
};

type Page = {
    data: SavedMatch[];
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

function MatchList({
    title,
    type,
    page,
}: {
    title: string;
    type: 'recording' | 'catalog';
    page: Page;
}) {
    return (
        <section className="rounded-xl border p-5">
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">
                {page.total} saved {page.total === 1 ? 'choice' : 'choices'}
            </p>
            <div className="mt-4 flex flex-col gap-4">
                {page.data.map((item) => (
                    <div
                        key={item.id}
                        className="flex flex-col gap-3 border-b pb-4"
                    >
                        <div>
                            <p className="font-medium">
                                {item.source_track ??
                                    'Original track unavailable'}{' '}
                                —{' '}
                                {item.source_artist ??
                                    'original artist unavailable'}
                                {item.source_album
                                    ? ` · ${item.source_album}`
                                    : ''}
                            </p>
                            {type === 'recording' ? (
                                <p className="text-sm text-muted-foreground">
                                    MusicBrainz:{' '}
                                    {item.match.recording_name ?? 'Recording'} ·{' '}
                                    {item.match.artist_name}{' '}
                                    <a
                                        className="underline"
                                        target="_blank"
                                        rel="noreferrer"
                                        href={`https://musicbrainz.org/recording/${encodeURIComponent(item.match.recording_mbid ?? '')}`}
                                    >
                                        View recording
                                    </a>
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {item.match.provider === 'spotify'
                                        ? 'Spotify'
                                        : 'Apple Music'}
                                    : {item.match.track_name} ·{' '}
                                    {item.match.artist_name}
                                    {item.match.release_name
                                        ? ` · ${item.match.release_name}`
                                        : ''}
                                    {item.match.id && item.match.provider && (
                                        <>
                                            {' '}
                                            <a
                                                className="underline"
                                                target="_blank"
                                                rel="noreferrer"
                                                href={
                                                    item.match.provider ===
                                                    'spotify'
                                                        ? `https://open.spotify.com/track/${encodeURIComponent(item.match.id)}`
                                                        : `https://music.apple.com/us/song/${encodeURIComponent(item.match.id)}`
                                                }
                                            >
                                                View catalog track
                                            </a>
                                        </>
                                    )}
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap items-start gap-3 text-sm">
                            {item.listen_id !== null && (
                                <Link
                                    className="underline"
                                    href={listenShow(item.listen_id)}
                                >
                                    Open related listen
                                </Link>
                            )}
                            {type === 'recording' && (
                                <Link
                                    className="underline"
                                    href={edit(item.id)}
                                >
                                    Change future match
                                </Link>
                            )}
                            <details>
                                <summary className="cursor-pointer underline">
                                    Remove saved choice
                                </summary>
                                <p className="my-2 text-muted-foreground">
                                    Existing listens and catalog metadata will
                                    not be changed.
                                </p>
                                <Form
                                    {...destroy.form({ type, match: item.id })}
                                >
                                    {({ processing }) => (
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Confirm removal
                                        </Button>
                                    )}
                                </Form>
                            </details>
                        </div>
                    </div>
                ))}
                {page.data.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No saved choices.
                    </p>
                )}
            </div>
            <nav
                aria-label={`${title} pagination`}
                className="mt-4 flex flex-wrap gap-2"
            >
                {page.links.map((link, i) =>
                    link.url ? (
                        <Link
                            key={i}
                            href={link.url}
                            className={`rounded border px-3 py-1 text-sm ${link.active ? 'bg-primary text-primary-foreground' : ''}`}
                        >
                            {link.label
                                .replace('&laquo;', '‹')
                                .replace('&raquo;', '›')}
                        </Link>
                    ) : (
                        <span
                            key={i}
                            className="rounded border px-3 py-1 text-sm text-muted-foreground"
                        >
                            {link.label
                                .replace('&laquo;', '‹')
                                .replace('&raquo;', '›')}
                        </span>
                    ),
                )}
            </nav>
        </section>
    );
}

export default function ManualMatches({
    recordings,
    catalogs,
}: {
    recordings: Page;
    catalogs: Page;
}) {
    return (
        <>
            <Head title="Manual matches" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Manual matches</h1>
                    <p className="text-sm text-muted-foreground">
                        Manage saved choices used for future imports. Changing
                        or removing a choice here does not alter existing
                        listens. To change a catalog choice, open a related
                        listen and save a new catalog match; that also updates
                        matching listens.
                    </p>
                </div>
                <MatchList
                    title="MusicBrainz recordings"
                    type="recording"
                    page={recordings}
                />
                <MatchList
                    title="Catalog matches"
                    type="catalog"
                    page={catalogs}
                />
            </div>
        </>
    );
}

ManualMatches.layout = {
    breadcrumbs: [{ title: 'Manual matches', href: index() }],
};
