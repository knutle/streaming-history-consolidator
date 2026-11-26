import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as albumShow } from '@/routes/albums';
import { show as artistShow } from '@/routes/artists';
import { index, show } from '@/routes/listens';
import { index as importsIndex } from '@/routes/imports';

type Listen = {
    id: number;
    listened_at: number;
    track_name: string;
    artist_name: string;
    release_name: string | null;
    source: 'spotify' | 'apple';
    played_ms: number;
    recording_mbid: string | null;
    matched_artist_mbids: string[];
    matched_album_mbids: string[];
};

type Props = {
    listens: {
        data: Listen[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: {
        search?: string;
        source?: string;
        match?: string;
        from?: string;
        to?: string;
        sort?: string;
        direction?: string;
    };
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs';

export default function ListeningHistory({ listens, filters }: Props) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Listening history" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Listening history
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Browse your Spotify and Apple Music history.{' '}
                        <Link href={importsIndex()} className="underline">
                            Import history.
                        </Link>
                        {' · '}
                        <Link
                            href={index({ query: { match: 'unmatched' } })}
                            className="underline"
                        >
                            Identify unmatched listens
                        </Link>
                    </p>
                </div>

                <section className="rounded-xl border p-4">
                    <h2 className="text-lg font-semibold">Browse listens</h2>
                    <Form
                        {...index.form()}
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="search">Search</Label>
                            <Input
                                id="search"
                                name="search"
                                defaultValue={filters.search}
                                placeholder="Track, artist, or album"
                            />
                            <InputError message={errors.search} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="source">Source</Label>
                            <select
                                id="source"
                                name="source"
                                className={selectClass}
                                defaultValue={filters.source ?? ''}
                            >
                                <option value="">All sources</option>
                                <option value="spotify">Spotify</option>
                                <option value="apple">Apple Music</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="match">MusicBrainz match</Label>
                            <select
                                id="match"
                                name="match"
                                className={selectClass}
                                defaultValue={filters.match ?? ''}
                            >
                                <option value="">All listens</option>
                                <option value="matched">Matched</option>
                                <option value="unmatched">Unmatched</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sort">Sort by</Label>
                            <select
                                id="sort"
                                name="sort"
                                className={selectClass}
                                defaultValue={filters.sort ?? 'listened_at'}
                            >
                                <option value="listened_at">Date</option>
                                <option value="track_name">Track</option>
                                <option value="artist_name">Artist</option>
                                <option value="release_name">Album</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="from">From (UTC)</Label>
                            <Input
                                id="from"
                                name="from"
                                type="date"
                                defaultValue={filters.from}
                            />
                            <InputError message={errors.from} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="to">To (UTC)</Label>
                            <Input
                                id="to"
                                name="to"
                                type="date"
                                defaultValue={filters.to}
                            />
                            <InputError message={errors.to} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="direction">Order</Label>
                            <select
                                id="direction"
                                name="direction"
                                className={selectClass}
                                defaultValue={filters.direction ?? 'desc'}
                            >
                                <option value="desc">Descending</option>
                                <option value="asc">Ascending</option>
                            </select>
                        </div>
                        <div className="flex items-end gap-2">
                            <Button>Apply</Button>
                            <Button variant="outline" asChild>
                                <Link href={index()}>Clear</Link>
                            </Button>
                        </div>
                    </Form>

                    <p className="mt-6 text-sm text-muted-foreground">
                        {listens.total}{' '}
                        {listens.total === 1 ? 'listen' : 'listens'}
                    </p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b text-muted-foreground">
                                    <th className="p-2">Played (UTC)</th>
                                    <th className="p-2">Track</th>
                                    <th className="p-2">Artist</th>
                                    <th className="p-2">Album</th>
                                    <th className="p-2">Source</th>
                                    <th className="p-2">MusicBrainz</th>
                                </tr>
                            </thead>
                            <tbody>
                                {listens.data.map((listen) => (
                                    <tr key={listen.id} className="border-b">
                                        <td className="p-2 whitespace-nowrap">
                                            {new Date(listen.listened_at * 1000)
                                                .toISOString()
                                                .replace('T', ' ')
                                                .slice(0, 19)}
                                        </td>
                                        <td className="p-2">
                                            <Link
                                                href={show(listen.id)}
                                                className="underline"
                                            >
                                                {listen.track_name}
                                            </Link>
                                        </td>
                                        <td className="p-2">
                                            {listen.matched_artist_mbids
                                                .length === 1 ? (
                                                <Link
                                                    className="underline"
                                                    href={artistShow(
                                                        listen
                                                            .matched_artist_mbids[0],
                                                    )}
                                                >
                                                    {listen.artist_name}
                                                </Link>
                                            ) : (
                                                listen.artist_name
                                            )}
                                        </td>
                                        <td className="p-2">
                                            {listen.release_name &&
                                            listen.matched_album_mbids
                                                .length === 1 ? (
                                                <Link
                                                    className="underline"
                                                    href={albumShow(
                                                        listen
                                                            .matched_album_mbids[0],
                                                    )}
                                                >
                                                    {listen.release_name}
                                                </Link>
                                            ) : (
                                                (listen.release_name ?? '—')
                                            )}
                                        </td>
                                        <td className="p-2">
                                            {listen.source === 'spotify'
                                                ? 'Spotify'
                                                : 'Apple Music'}
                                        </td>
                                        <td className="p-2">
                                            {listen.recording_mbid ? (
                                                <a
                                                    className="underline"
                                                    href={`https://musicbrainz.org/recording/${listen.recording_mbid}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Matched
                                                </a>
                                            ) : (
                                                'Unmatched'
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {listens.data.length === 0 && (
                            <p className="py-6 text-sm text-muted-foreground">
                                No listens found.
                            </p>
                        )}
                    </div>
                    <nav
                        aria-label="Pagination"
                        className="mt-4 flex flex-wrap gap-2"
                    >
                        {listens.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    className={`rounded border px-3 py-1 text-sm ${link.active ? 'bg-primary text-primary-foreground' : ''}`}
                                >
                                    {link.label
                                        .replace('&laquo;', '‹')
                                        .replace('&raquo;', '›')}
                                </Link>
                            ) : (
                                <span
                                    key={index}
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
            </div>
        </>
    );
}

ListeningHistory.layout = {
    breadcrumbs: [{ title: 'Listening history', href: index() }],
};
