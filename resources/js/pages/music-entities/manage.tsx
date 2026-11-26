import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as albumsIndex, show as albumShow } from '@/routes/albums';
import { index as artistsIndex, show as artistShow } from '@/routes/artists';
import { show as listenShow } from '@/routes/listens';
import { catalog, manage, musicbrainz, remove } from '@/routes/music-entities';

type Props = {
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
    suggestions: { mbid: string; name: string; context: string | null }[];
    hasMore: boolean;
    musicbrainzEnabled: boolean;
    entityResult: string | null;
};

export default function ManageMusicEntity({
    type,
    item,
    canMatch,
    saved,
    matched,
    listens,
    search,
    searchPage,
    suggestions,
    hasMore,
    musicbrainzEnabled,
    entityResult,
}: Props) {
    const { errors } = usePage().props;
    const args = { type, listen: item.listen_id };
    const title = type === 'artist' ? item.artist_name : item.release_name;
    const indexRoute = type === 'artist' ? artistsIndex : albumsIndex;
    const showRoute = type === 'artist' ? artistShow : albumShow;
    return (
        <>
            <Head title={`Match ${title}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link href={indexRoute()} className="text-sm underline">
                    Back to {type === 'artist' ? 'artists' : 'albums'}
                </Link>
                <div>
                    <h1 className="text-2xl font-semibold">{title}</h1>
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
                </div>
                <section className="rounded-xl border p-4">
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
                <section className="rounded-xl border p-4">
                    <h2 className="text-lg font-semibold">Match MusicBrainz</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Search the local MusicBrainz database by name prefix.
                        Album results show an associated artist credit; verify
                        the release before selecting it.
                    </p>
                    {!musicbrainzEnabled && (
                        <p className="mt-2 text-sm">
                            MusicBrainz is not enabled.
                        </p>
                    )}
                    <Form
                        {...manage.form(args)}
                        className="mt-3 flex flex-wrap items-end gap-2"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="q">Name prefix</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={search}
                                required
                            />
                        </div>
                        <Button disabled={!musicbrainzEnabled}>Search</Button>
                    </Form>
                    <InputError message={errors.q} />
                    <InputError message={errors.mbid} />
                    <InputError message={errors.name} />
                    {search && suggestions.length === 0 && (
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
                                    query: { q: search, page: searchPage - 1 },
                                })}
                            >
                                Previous results
                            </Link>
                        )}
                        {hasMore && (
                            <Link
                                className="underline"
                                href={manage(args, {
                                    query: { q: search, page: searchPage + 1 },
                                })}
                            >
                                Next results
                            </Link>
                        )}
                    </div>
                </section>
                <section className="rounded-xl border p-4">
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
                                        <div className="min-w-60 flex-1">
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
                <section className="rounded-xl border p-4">
                    <h2 className="text-lg font-semibold">Related listens</h2>
                    <ul className="mt-3 divide-y">
                        {listens.data.map((listen) => (
                            <li key={listen.id} className="py-2 text-sm">
                                <Link
                                    href={listenShow(listen.id)}
                                    className="underline"
                                >
                                    {listen.track_name}
                                </Link>{' '}
                                ·{' '}
                                {new Date(listen.listened_at * 1000)
                                    .toISOString()
                                    .replace('T', ' ')
                                    .slice(0, 19)}{' '}
                                UTC
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
