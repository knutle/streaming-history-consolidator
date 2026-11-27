import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import AlbumCover from '@/components/album-cover';
import type { ArtworkProps } from '@/components/album-cover';
import ArtworkPicker from '@/components/artwork-picker';
import DetailHeader from '@/components/detail-header';
import Form from '@/components/form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as albumsIndex, show as albumShow } from '@/routes/albums';
import { index as artistsIndex, show as artistShow } from '@/routes/artists';
import { show as listenShow } from '@/routes/listens';
import { catalog, manage, musicbrainz, remove } from '@/routes/music-entities';

type Props = ArtworkProps & {
    type: 'artist' | 'album';
    item: {
        listen_id: number;
        artist_name: string;
        release_name: string | null;
    };
    canMatch: boolean;
    saved: {
        mbid: string | null;
        name: string | null;
        catalogs: Record<string, string>;
    } | null;
    matched: { mbid: string; name: string | null }[];
    listens: {
        data: { id: number; track_name: string; listened_at: number }[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    search: string;
    searchPage: number;
    searchSource: 'local' | 'api';
    apiError: string | null;
    suggestions: { mbid: string; name: string; context: string | null }[];
    hasMore: boolean;
    musicbrainzEnabled: boolean;
    entityResult: string | null;
    artistScan: {
        status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
        source: 'local' | 'api';
        processed: number;
        matched: number;
        error: string | null;
    } | null;
    musicBrainzSeed: {
        action: string;
        method: 'get' | 'post';
        fields: Record<string, string>;
    } | null;
};

function PollArtistScan() {
    usePoll(3000, { only: ['artistScan'] });

    return null;
}

export default function ManageMusicEntity({
    type,
    item,
    canMatch,
    saved,
    matched,
    listens,
    search,
    searchPage,
    searchSource,
    apiError,
    suggestions,
    hasMore,
    musicbrainzEnabled,
    entityResult,
    artistScan,
    musicBrainzSeed,
    artworkEnabled,
    artwork,
    artworkResult,
}: Props) {
    const { errors } = usePage().props;
    const args = { type, listen: item.listen_id };
    const title = type === 'artist' ? item.artist_name : item.release_name;
    const indexRoute = type === 'artist' ? artistsIndex : albumsIndex;
    const showRoute = type === 'artist' ? artistShow : albumShow;
    return (
        <>
            <Head title={`Match ${title}`} />
            <div className="flex min-w-0 flex-col gap-5 px-5 py-8 [overflow-wrap:anywhere] sm:px-8">
                <DetailHeader
                    back={indexRoute()}
                    backLabel={`Back to ${type === 'artist' ? 'artists' : 'albums'}`}
                    eyebrow={`Your ${type} group`}
                    title={title ?? 'Unknown album'}
                    media={
                        <AlbumCover
                            artworkEnabled={artworkEnabled}
                            artwork={artwork}
                            title={title ?? 'Unknown album'}
                            artist={type === 'artist'}
                        />
                    }
                >
                    {type === 'album' && (
                        <p className="text-muted-foreground">
                            {item.artist_name}
                        </p>
                    )}
                    <p className="mt-2 text-sm text-muted-foreground">
                        This group uses the exact imported artist credit
                        {type === 'album' ? ' and album name' : ''}. A manual
                        MusicBrainz choice replaces the existing {type}{' '}
                        associations for all {listens.total} matching listens
                        and applies to future imports.
                        {type === 'artist' &&
                            ' For a multi-artist credit, the chosen artist replaces all artists in that credit.'}{' '}
                        Catalog links are saved for this group; they do not
                        change MusicBrainz associations.
                    </p>
                    {!canMatch && (
                        <p className="mt-2 text-sm text-destructive">
                            This group lacks a reliable artist or album identity
                            and cannot be manually matched.
                        </p>
                    )}
                    {entityResult && (
                        <p role="status" className="mt-2 text-sm">
                            {entityResult}
                        </p>
                    )}
                    <InputError message={errors.match} />
                    {artistScan && (
                        <div className="mt-2 text-sm" role="status">
                            {(artistScan.status === 'queued' ||
                                artistScan.status === 'running') && (
                                <PollArtistScan />
                            )}
                            Artist recording scan: {artistScan.status} ·{' '}
                            {artistScan.processed} checked ·{' '}
                            {artistScan.matched} listens matched (
                            {artistScan.source}).
                            {artistScan.error && <p>{artistScan.error}</p>}
                            {artistScan.status === 'queued' && (
                                <p>
                                    Requires the imports queue worker to be
                                    running.
                                </p>
                            )}
                        </div>
                    )}
                </DetailHeader>
                {artworkEnabled && type === 'album' && item.release_name && (
                    <ArtworkPicker
                        key={item.listen_id}
                        listenId={item.listen_id}
                        album={item.release_name}
                        result={artworkResult}
                    />
                )}
                <section className="rounded-xl border bg-card p-5 shadow-sm sm:p-6">
                    <h2 className="text-lg font-semibold">
                        Current MusicBrainz associations
                    </h2>
                    {saved?.mbid && (
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            <span>
                                Manual choice: {saved.name} · {saved.mbid}
                            </span>
                            <Form
                                {...remove.form({
                                    ...args,
                                    kind: 'musicbrainz',
                                })}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Remove saved choice
                                    </Button>
                                )}
                            </Form>
                        </div>
                    )}
                    {saved?.mbid && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Removing the saved choice affects future imports
                            only; existing associations remain unchanged.
                        </p>
                    )}
                    {matched.length === 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            No MusicBrainz associations.
                        </p>
                    ) : (
                        <ul className="mt-2 space-y-1 text-sm">
                            {matched.map((match) => (
                                <li key={match.mbid}>
                                    <Link
                                        href={showRoute(match.mbid)}
                                        className="underline"
                                    >
                                        {match.name ?? match.mbid}
                                    </Link>{' '}
                                    · {match.mbid}
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="mt-2 text-xs text-muted-foreground">
                        Showing up to 30 associations.
                    </p>
                </section>
                <section className="rounded-xl border border-emerald-200 bg-emerald-50/40 p-5 sm:p-6 dark:border-emerald-900 dark:bg-emerald-950/15">
                    <h2 className="text-lg font-semibold">Match MusicBrainz</h2>
                    {musicBrainzSeed && (
                        <div className="mt-3 text-sm">
                            <form
                                action={musicBrainzSeed.action}
                                method={musicBrainzSeed.method}
                                encType={
                                    musicBrainzSeed.method === 'post'
                                        ? 'multipart/form-data'
                                        : undefined
                                }
                                target="_blank"
                                rel="noreferrer"
                            >
                                {Object.entries(musicBrainzSeed.fields).map(
                                    ([name, value]) => (
                                        <input
                                            key={name}
                                            type="hidden"
                                            name={name}
                                            value={value}
                                        />
                                    ),
                                )}
                                <Button variant="outline" type="submit">
                                    Open{' '}
                                    {type === 'artist' ? 'artist' : 'release'}{' '}
                                    editor with sources
                                </Button>
                            </form>
                            <p className="mt-2 text-muted-foreground">
                                This sends the imported name and any saved
                                catalog links to MusicBrainz. They are
                                unverified: check the sources and edit note,
                                search for existing entries, and review names
                                and artist credits against the{' '}
                                <a
                                    className="underline"
                                    href="https://musicbrainz.org/doc/Style"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    MusicBrainz style guidelines
                                </a>{' '}
                                before submitting. A combined artist credit may
                                need to be split into individual artists. Sign
                                in to MusicBrainz before opening the editor so
                                the prefilled data is retained.
                            </p>
                        </div>
                    )}
                    <p className="mt-1 text-sm text-muted-foreground">
                        Search the local MusicBrainz database by name prefix.
                        Album results show an associated artist credit; verify
                        the release before selecting it.
                    </p>
                    <p className="mt-2 text-sm text-muted-foreground">
                        API searches send the entered name to MusicBrainz.
                        Selecting an API result also checks it online. Imports
                        continue to use local matching.
                    </p>
                    {type === 'artist' && (
                        <p className="mt-2 text-sm text-muted-foreground">
                            Selecting an artist queues a scan of its unmatched
                            listens. The local scan is offline. Choosing API
                            fallback also sends unmatched track and album names
                            to MusicBrainz; only unambiguous exact matches are
                            applied.
                        </p>
                    )}
                    {!musicbrainzEnabled && searchSource === 'local' && (
                        <p className="mt-2 text-sm">
                            Local MusicBrainz matching is disabled. Choose the
                            MusicBrainz API to search online.
                        </p>
                    )}
                    <Form
                        {...manage.form(args)}
                        className="mt-3 flex flex-wrap items-end gap-2"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="q">
                                Name (local search uses a prefix)
                            </Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={search}
                                maxLength={255}
                                required
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="entity-search-source">
                                Search source
                            </Label>
                            <select
                                key={searchSource}
                                id="entity-search-source"
                                name="search_source"
                                defaultValue={searchSource}
                                className="h-9 rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="local">
                                    Local MusicBrainz database
                                </option>
                                <option value="api">
                                    MusicBrainz API (online)
                                </option>
                            </select>
                        </div>
                        <Button>Search</Button>
                    </Form>
                    <InputError message={errors.q} />
                    <InputError message={errors.mbid} />
                    <InputError message={errors.name} />
                    <InputError message={errors.page ?? errors.search_source} />
                    {apiError && (
                        <p
                            role="alert"
                            className="mt-2 text-sm text-destructive"
                        >
                            {apiError}
                        </p>
                    )}
                    {search &&
                        !apiError &&
                        (musicbrainzEnabled || searchSource === 'api') &&
                        suggestions.length === 0 && (
                            <p className="mt-3 text-sm text-muted-foreground">
                                No results found.
                            </p>
                        )}
                    <ul className="mt-3 divide-y">
                        {suggestions.map((suggestion) => (
                            <li
                                key={`${suggestion.mbid}:${suggestion.context ?? ''}`}
                                className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                            >
                                <div>
                                    <div>
                                        {suggestion.name}
                                        {suggestion.context
                                            ? ` · ${suggestion.context}`
                                            : ''}
                                    </div>
                                    <a
                                        className="text-muted-foreground underline"
                                        href={`https://musicbrainz.org/${type === 'album' ? 'release' : 'artist'}/${encodeURIComponent(suggestion.mbid)}`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {suggestion.mbid}
                                    </a>
                                </div>
                                <Form {...musicbrainz.form(args)}>
                                    {({ processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="mbid"
                                                value={suggestion.mbid}
                                            />
                                            <input
                                                type="hidden"
                                                name="name"
                                                value={suggestion.name}
                                            />
                                            <input
                                                type="hidden"
                                                name="search_source"
                                                value={searchSource}
                                            />
                                            {type === 'artist' && (
                                                <label className="mr-2">
                                                    <span className="sr-only">
                                                        Recording scan source
                                                    </span>
                                                    <select
                                                        name="scan_source"
                                                        defaultValue="local"
                                                        className="h-9 rounded-md border bg-background px-2 text-sm"
                                                    >
                                                        <option value="local">
                                                            Local scan
                                                        </option>
                                                        <option value="api">
                                                            Local + API scan
                                                        </option>
                                                    </select>
                                                </label>
                                            )}
                                            <Button
                                                size="sm"
                                                disabled={
                                                    processing || !canMatch
                                                }
                                            >
                                                Select
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-3 flex gap-4 text-sm">
                        {searchPage > 1 && (
                            <Link
                                className="underline"
                                href={manage(args, {
                                    query: {
                                        q: search,
                                        page: searchPage - 1,
                                        search_source: searchSource,
                                    },
                                })}
                            >
                                Previous results
                            </Link>
                        )}
                        {hasMore && (
                            <Link
                                className="underline"
                                href={manage(args, {
                                    query: {
                                        q: search,
                                        page: searchPage + 1,
                                        search_source: searchSource,
                                    },
                                })}
                            >
                                Next results
                            </Link>
                        )}
                    </div>
                </section>
                <section className="rounded-xl border bg-card p-5 shadow-sm sm:p-6">
                    <h2 className="text-lg font-semibold">Catalog links</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Paste an {type} URL, or a Spotify {type} URI. Catalog
                        metadata is not retrieved or verified.
                    </p>
                    {(['spotify', 'apple'] as const).map((provider) => (
                        <div key={provider} className="mt-4">
                            <h3 className="font-medium">
                                {provider === 'spotify'
                                    ? 'Spotify'
                                    : 'Apple Music'}
                            </h3>
                            {saved?.catalogs[provider] && (
                                <div className="mt-1 flex items-center gap-2">
                                    <a
                                        className="text-sm underline"
                                        target="_blank"
                                        rel="noreferrer"
                                        href={
                                            provider === 'spotify'
                                                ? `https://open.spotify.com/${type}/${saved.catalogs[provider]}`
                                                : `https://music.apple.com/${type}/${saved.catalogs[provider]}`
                                        }
                                    >
                                        View saved catalog link
                                    </a>
                                    <Form
                                        {...remove.form({
                                            ...args,
                                            kind: provider,
                                        })}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            )}
                            <Form
                                {...catalog.form(args)}
                                className="mt-2 flex flex-wrap gap-2"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="provider"
                                            value={provider}
                                        />
                                        <div className="min-w-0 flex-1 sm:min-w-60">
                                            <Label
                                                htmlFor={`${provider}-url`}
                                                className="sr-only"
                                            >
                                                {provider} {type} URL
                                            </Label>
                                            <Input
                                                id={`${provider}-url`}
                                                name="catalog_url"
                                                placeholder={`${provider} ${type} URL`}
                                                required
                                            />
                                            <InputError
                                                message={formErrors.catalog_url}
                                            />
                                            <InputError
                                                message={formErrors.match}
                                            />
                                        </div>
                                        <Button
                                            disabled={processing || !canMatch}
                                        >
                                            Save
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </div>
                    ))}
                </section>
                <section className="rounded-xl border bg-card p-5 shadow-sm sm:p-6">
                    <h2 className="text-lg font-semibold">Related listens</h2>
                    <ul className="mt-3 divide-y">
                        {listens.data.map((listen) => (
                            <li
                                key={listen.id}
                                className="flex flex-col gap-1 py-3 text-sm sm:flex-row sm:justify-between sm:gap-4"
                            >
                                <Link
                                    href={listenShow(listen.id)}
                                    className="min-w-0 font-medium [overflow-wrap:anywhere] underline-offset-4 hover:underline"
                                >
                                    {listen.track_name}
                                </Link>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {new Date(listen.listened_at * 1000)
                                        .toISOString()
                                        .replace('T', ' ')
                                        .slice(0, 19)}{' '}
                                    UTC
                                </span>
                            </li>
                        ))}
                    </ul>
                    <nav
                        aria-label="Listen pages"
                        className="mt-4 flex flex-wrap gap-3 text-sm"
                    >
                        {listens.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    className={
                                        link.active ? 'font-bold' : 'underline'
                                    }
                                >
                                    {link.label
                                        .replace(/&laquo;|&raquo;/g, '')
                                        .trim()}
                                </Link>
                            ) : null,
                        )}
                    </nav>
                </section>
            </div>
        </>
    );
}
