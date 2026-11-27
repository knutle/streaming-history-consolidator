import { Head, Link } from '@inertiajs/react';
import AlbumCover from '@/components/album-cover';
import type { ArtworkProps } from '@/components/album-cover';
import DetailHeader from '@/components/detail-header';
import Form from '@/components/form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { lookupName } from '@/routes/artists';
import { index, show } from '@/routes/listens';
import { index as albumsIndex } from '@/routes/albums';
import { index as artistsIndex } from '@/routes/artists';

type Props = ArtworkProps & {
    entity: {
        mbid: string;
        name: string | null;
        type: 'artist' | 'album';
        nameSource: string | null;
    };
    tracks: {
        listen_id: number;
        listen_count: number;
        track_name: string;
        artist_name: string;
    }[];
    listens: {
        total: number;
        data: {
            id: number;
            track_name: string;
            artist_name: string;
            release_name: string | null;
            listened_at: number;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function MusicEntityDetails({
    entity,
    tracks,
    listens,
    artworkEnabled,
    artwork,
}: Props) {
    const title = entity.name ?? 'MusicBrainz artist';

    return (
        <>
            <Head title={title} />
            <div className="flex min-w-0 flex-col gap-5 px-5 py-8 [overflow-wrap:anywhere] sm:px-8">
                <DetailHeader
                    back={
                        entity.type === 'artist'
                            ? artistsIndex()
                            : albumsIndex()
                    }
                    backLabel={`Back to ${entity.type === 'artist' ? 'artists' : 'albums'}`}
                    eyebrow={
                        entity.type === 'artist'
                            ? 'Artist details'
                            : 'Album details'
                    }
                    title={title}
                    media={
                        <AlbumCover
                            artworkEnabled={artworkEnabled}
                            artwork={artwork}
                            title={title}
                            artist={entity.type === 'artist'}
                        />
                    }
                >
                    <p className="text-muted-foreground">
                        {listens.total.toLocaleString()} listens in your history
                    </p>
                    {entity.nameSource === 'canonical_credit' && (
                        <p className="text-sm text-muted-foreground">
                            Name from a MusicBrainz artist credit.
                        </p>
                    )}
                    {entity.type === 'artist' && entity.name === null && (
                        <div className="my-2 text-sm">
                            <p className="text-muted-foreground">
                                The artist name is not available locally.
                                Looking it up sends this artist&apos;s
                                MusicBrainz ID to MusicBrainz.
                            </p>
                            <Form {...lookupName.form(entity.mbid)}>
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Look up artist name
                                        </Button>
                                        <InputError
                                            message={errors.artist_name}
                                        />
                                    </>
                                )}
                            </Form>
                        </div>
                    )}
                    <a
                        className="text-sm underline"
                        href={`https://musicbrainz.org/${entity.type === 'artist' ? 'artist' : 'release'}/${encodeURIComponent(entity.mbid)}`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        View on MusicBrainz
                    </a>
                </DetailHeader>
                <section className="rounded-xl border bg-card p-5 shadow-sm sm:p-6">
                    <h2 className="text-lg font-semibold">Related tracks</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Showing up to 100 tracks from your listening history.
                    </p>
                    <ul className="mt-3 divide-y">
                        {tracks.map((track) => (
                            <li
                                key={track.listen_id}
                                className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                            >
                                <Link
                                    className="min-w-0 font-medium [overflow-wrap:anywhere] underline-offset-4 hover:underline"
                                    href={show(track.listen_id)}
                                >
                                    {track.track_name} · {track.artist_name}
                                </Link>
                                <span className="rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground">
                                    {track.listen_count.toLocaleString()}{' '}
                                    listens
                                </span>
                            </li>
                        ))}
                    </ul>
                    {tracks.length === 0 && (
                        <p className="mt-3 text-sm text-muted-foreground">
                            No related tracks found.
                        </p>
                    )}
                </section>
                <section className="rounded-xl border bg-card p-5 shadow-sm sm:p-6">
                    <h2 className="text-lg font-semibold">Your listens</h2>
                    <ul className="mt-3 divide-y">
                        {listens.data.map((listen) => (
                            <li
                                key={listen.id}
                                className="flex flex-col gap-1 py-3 text-sm sm:flex-row sm:justify-between sm:gap-4"
                            >
                                <Link
                                    className="min-w-0 font-medium [overflow-wrap:anywhere] underline-offset-4 hover:underline"
                                    href={show(listen.id)}
                                >
                                    {listen.track_name} · {listen.artist_name}
                                </Link>
                                <span className="text-xs text-muted-foreground">
                                    {listen.release_name
                                        ? `${listen.release_name} · `
                                        : ''}
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

MusicEntityDetails.layout = {
    breadcrumbs: [{ title: 'Listening history', href: index() }],
};
