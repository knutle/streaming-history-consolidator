import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Form from '@/components/form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as albumShow } from '@/routes/albums';
import { show as artistShow } from '@/routes/artists';
import { index, show } from '@/routes/listens';
import {
    cancel as cancelBulk,
    resume as resumeBulk,
    review as reviewBulk,
    selection as updateSelection,
    store as startBulk,
} from '@/routes/listens/bulk-musicbrainz';
import { manage as manageEntity } from '@/routes/music-entities';
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
    bulkSelection: {
        ids: number[];
        count: number;
        all: boolean;
        needsClear: boolean;
    };
    pendingMusicBrainzCount: number;
    bulkRun: {
        id: number;
        status:
            | 'queued'
            | 'running'
            | 'waiting'
            | 'paused'
            | 'completed'
            | 'cancelled'
            | 'failed';
        total: number;
        processed: number;
        matched: number;
        unmatched: number;
        skipped: number;
        propagated: number;
        error: string | null;
        retry_at: string | null;
    } | null;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs';

function PollBulkMatch() {
    usePoll(3000, {
        only: [
            'bulkRun',
            'bulkSelection',
            'listens',
            'pendingMusicBrainzCount',
        ],
    });

    return null;
}

export default function ListeningHistory({
    listens,
    filters,
    bulkSelection,
    bulkRun,
    pendingMusicBrainzCount,
}: Props) {
    const { errors } = usePage().props;
    const [updatingSelection, setUpdatingSelection] = useState(false);
    const activeRun =
        bulkRun !== null &&
        ['queued', 'running', 'waiting', 'paused'].includes(bulkRun.status);
    const visibleUnmatched = listens.data.filter(
        (listen) => listen.recording_mbid === null,
    );
    useEffect(() => {
        if (bulkSelection.needsClear) {
            router.post(
                updateSelection.url(),
                { ...filters, action: 'clear' },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setUpdatingSelection(true),
                    onFinish: () => setUpdatingSelection(false),
                },
            );
        }
    }, [bulkSelection.needsClear, filters]);
    const changeSelection = (
        action: 'all' | 'clear' | 'add' | 'remove',
        ids: number[] = [],
    ) => {
        router.post(
            updateSelection.url(),
            { ...filters, action, ids },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setUpdatingSelection(true),
                onFinish: () => setUpdatingSelection(false),
            },
        );
    };

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

                    <div className="mt-6 space-y-3 rounded-lg border p-4">
                        <h2 className="font-semibold">
                            Match selected listens with MusicBrainz
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            This sends selected track, artist and album names to
                            MusicBrainz. Only unique exact title and artist
                            matches are applied; if no album match is found, the
                            search is retried without the album. Linked catalog
                            recordings may also update unselected listens.
                            Matching runs in the background and requires the
                            imports queue worker and a cache shared by all app
                            workers and instances for MusicBrainz throttling. If
                            needed, run{' '}
                            <code>
                                php artisan queue:work imports --queue=imports
                                --timeout=7200 --tries=1
                            </code>
                            .
                        </p>
                        <p className="text-sm" role="status">
                            {bulkSelection.count} selected
                            {bulkSelection.all &&
                                ' (all filtered unmatched listens)'}
                        </p>
                        <p className="text-sm">
                            <Link href={reviewBulk()} className="underline">
                                Review pending MusicBrainz matches (
                                {pendingMusicBrainzCount})
                            </Link>
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={updatingSelection || activeRun}
                                onClick={() => changeSelection('all')}
                            >
                                Select all filtered unmatched listens
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    updatingSelection ||
                                    activeRun ||
                                    bulkSelection.count === 0
                                }
                                onClick={() => changeSelection('clear')}
                            >
                                Clear selection
                            </Button>
                            <Form
                                {...startBulk.form()}
                                onBefore={() =>
                                    window.confirm(
                                        'Send the selected track, artist and album names to MusicBrainz for automatic matching?',
                                    )
                                }
                            >
                                {Object.entries(filters).map(([key, value]) =>
                                    value ? (
                                        <input
                                            key={key}
                                            type="hidden"
                                            name={key}
                                            value={value}
                                        />
                                    ) : null,
                                )}
                                <input type="hidden" name="confirm" value="1" />
                                <Button
                                    disabled={
                                        updatingSelection ||
                                        activeRun ||
                                        bulkSelection.count === 0
                                    }
                                >
                                    Queue matching
                                </Button>
                            </Form>
                        </div>
                        <InputError
                            message={errors.selection ?? errors.confirm}
                        />
                        {bulkRun && (
                            <div className="space-y-2 text-sm" role="status">
                                {['queued', 'running', 'waiting'].includes(
                                    bulkRun.status,
                                ) && <PollBulkMatch />}
                                <p>
                                    Bulk match: {bulkRun.status} ·{' '}
                                    {bulkRun.processed}/{bulkRun.total}{' '}
                                    processed · {bulkRun.matched} matched ·{' '}
                                    {bulkRun.unmatched} unmatched ·{' '}
                                    {bulkRun.skipped} skipped ·{' '}
                                    {bulkRun.propagated} linked listens updated.
                                </p>
                                {bulkRun.error && <p>{bulkRun.error}</p>}
                                {bulkRun.retry_at && (
                                    <p>
                                        Retry scheduled at {bulkRun.retry_at}.
                                    </p>
                                )}
                                {activeRun && (
                                    <div className="flex gap-2">
                                        <Form {...cancelBulk.form(bulkRun.id)}>
                                            <Button variant="outline">
                                                Cancel matching
                                            </Button>
                                        </Form>
                                        {bulkRun.status === 'paused' && (
                                            <Form
                                                {...resumeBulk.form(bulkRun.id)}
                                            >
                                                <Button>Resume matching</Button>
                                            </Form>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    <p className="mt-6 text-sm text-muted-foreground">
                        {listens.total}{' '}
                        {listens.total === 1 ? 'listen' : 'listens'}
                    </p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b text-muted-foreground">
                                    <th className="p-2">
                                        <input
                                            type="checkbox"
                                            aria-label="Select unmatched listens on this page"
                                            checked={
                                                visibleUnmatched.length > 0 &&
                                                visibleUnmatched.every(
                                                    (listen) =>
                                                        bulkSelection.ids.includes(
                                                            listen.id,
                                                        ),
                                                )
                                            }
                                            disabled={
                                                updatingSelection ||
                                                activeRun ||
                                                visibleUnmatched.length === 0
                                            }
                                            onChange={(event) =>
                                                changeSelection(
                                                    event.target.checked
                                                        ? 'add'
                                                        : 'remove',
                                                    visibleUnmatched.map(
                                                        (listen) => listen.id,
                                                    ),
                                                )
                                            }
                                        />
                                    </th>
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
                                        <td className="p-2">
                                            <input
                                                type="checkbox"
                                                aria-label={`Select ${listen.track_name} by ${listen.artist_name}`}
                                                checked={bulkSelection.ids.includes(
                                                    listen.id,
                                                )}
                                                disabled={
                                                    updatingSelection ||
                                                    activeRun ||
                                                    listen.recording_mbid !==
                                                        null
                                                }
                                                onChange={(event) =>
                                                    changeSelection(
                                                        event.target.checked
                                                            ? 'add'
                                                            : 'remove',
                                                        [listen.id],
                                                    )
                                                }
                                            />
                                        </td>
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
                                            ) : listen.matched_artist_mbids
                                                  .length === 0 ? (
                                                <Link
                                                    className="underline"
                                                    href={manageEntity({
                                                        type: 'artist',
                                                        listen: listen.id,
                                                    })}
                                                >
                                                    {listen.artist_name}
                                                </Link>
                                            ) : (
                                                listen.artist_name
                                            )}
                                        </td>
                                        <td className="p-2">
                                            {listen.release_name?.trim() &&
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
                                            ) : listen.release_name?.trim() &&
                                              listen.matched_album_mbids
                                                  .length === 0 ? (
                                                <Link
                                                    className="underline"
                                                    href={manageEntity({
                                                        type: 'album',
                                                        listen: listen.id,
                                                    })}
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
