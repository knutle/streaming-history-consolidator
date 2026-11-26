import { Head, Link } from '@inertiajs/react';
import { index, show } from '@/routes/listens';

type Props = {
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

export default function MusicEntityDetails({ entity, tracks, listens }: Props) {
    const title = entity.name ?? 'MusicBrainz artist';

    return (
        <>
            <Head title={title} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link href={index()} className="text-sm underline">
                    Back to listening history
                </Link>
                <div>
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    <p className="text-sm text-muted-foreground">
                        {entity.type === 'artist' ? 'Artist' : 'Album'}
                    </p>
                    {entity.nameSource === 'canonical_credit' && (
                        <p className="text-sm text-muted-foreground">
                            Name from a MusicBrainz artist credit.
                        </p>
                    )}
                    <a
                        className="text-sm underline"
                        href={`https://musicbrainz.org/${entity.type === 'artist' ? 'artist' : 'release'}/${encodeURIComponent(entity.mbid)}`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        View on MusicBrainz
                    </a>
                </div>
                <section className="rounded-xl border p-5">
                    <h2 className="text-lg font-semibold">Related tracks</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Showing up to 100 tracks from your listening history.
                    </p>
                    <ul className="mt-3 divide-y">
                        {tracks.map((track) => (
                            <li key={track.listen_id} className="py-2 text-sm">
                                <Link
                                    className="underline"
                                    href={show(track.listen_id)}
                                >
                                    {track.track_name} · {track.artist_name}
                                </Link>{' '}
                                · {track.listen_count} listens
                            </li>
                        ))}
                    </ul>
                </section>
                <section className="rounded-xl border p-5">
                    <h2 className="text-lg font-semibold">Your listens</h2>
                    <ul className="mt-3 divide-y">
                        {listens.data.map((listen) => (
                            <li key={listen.id} className="py-2 text-sm">
                                <Link
                                    className="underline"
                                    href={show(listen.id)}
                                >
                                    {listen.track_name} · {listen.artist_name}
                                </Link>
                                {listen.release_name
                                    ? ` · ${listen.release_name}`
                                    : ''}
                                {' · '}
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

MusicEntityDetails.layout = {
    breadcrumbs: [{ title: 'Listening history', href: index() }],
};
