import { Head, Link, usePage } from '@inertiajs/react';
import Form from '@/components/form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { index as listensIndex, show } from '@/routes/listens';
import { approve, dismiss } from '@/routes/listens/bulk-musicbrainz';

type PendingMatch = {
    id: number;
    listen_id: number;
    track_name: string;
    artist_name: string;
    release_name: string | null;
    recording_mbid: string | null;
    incomplete: boolean;
    candidates: {
        recording_mbid: string;
        track_name: string;
        artist_name: string;
        releases: string[];
        album_verified: boolean;
    }[];
};

type Props = {
    pending: {
        data: PendingMatch[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function PendingMusicBrainzMatches({ pending }: Props) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Review MusicBrainz matches" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Review MusicBrainz matches
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        These results were not applied automatically. Approving
                        a recording may also update linked catalog listens;
                        existing matches and saved conflicting choices are not
                        overwritten.
                    </p>
                    <Link href={listensIndex()} className="text-sm underline">
                        Back to listening history
                    </Link>
                </div>
                <InputError message={errors.recording_mbid} />
                <p className="text-sm">{pending.total} pending reviews</p>
                {pending.data.map((item) => (
                    <section key={item.id} className="rounded-xl border p-5">
                        <h2 className="font-semibold">
                            {item.track_name} · {item.artist_name}
                            {item.release_name ? ` · ${item.release_name}` : ''}
                        </h2>
                        <Link
                            href={show(item.listen_id)}
                            className="text-sm underline"
                        >
                            View listen
                        </Link>
                        {item.recording_mbid && (
                            <p className="text-sm">
                                This listen has already been matched; dismiss
                                this review.
                            </p>
                        )}
                        {item.incomplete && (
                            <p className="text-sm">
                                MusicBrainz returned only part of the results;
                                other recordings may exist.
                            </p>
                        )}
                        <ul className="mt-3 divide-y">
                            {item.candidates.map((candidate) => (
                                <li
                                    key={candidate.recording_mbid}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                                >
                                    <div>
                                        <p>
                                            {candidate.track_name} ·{' '}
                                            {candidate.artist_name}
                                        </p>
                                        <p className="text-muted-foreground">
                                            Returned releases:{' '}
                                            {candidate.releases.length
                                                ? candidate.releases.join(', ')
                                                : 'none'}
                                            {!candidate.album_verified &&
                                                ' · original album not verified'}
                                        </p>
                                        <a
                                            href={`https://musicbrainz.org/recording/${encodeURIComponent(candidate.recording_mbid)}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline"
                                        >
                                            View recording on MusicBrainz
                                        </a>
                                    </div>
                                    <Form {...approve.form(item.id)}>
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="recording_mbid"
                                                    value={
                                                        candidate.recording_mbid
                                                    }
                                                />
                                                <Button
                                                    disabled={
                                                        processing ||
                                                        item.recording_mbid !==
                                                            null
                                                    }
                                                >
                                                    Approve
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                        <Form {...dismiss.form(item.id)}>
                            {({ processing }) => (
                                <Button variant="outline" disabled={processing}>
                                    Dismiss review
                                </Button>
                            )}
                        </Form>
                    </section>
                ))}
                {pending.total === 0 && (
                    <p className="text-sm">No matches need review.</p>
                )}
                <nav
                    aria-label="Pagination"
                    className="flex flex-wrap gap-3 text-sm"
                >
                    {pending.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                aria-current={link.active ? 'page' : undefined}
                            >
                                {link.label
                                    .replace('&laquo;', '‹')
                                    .replace('&raquo;', '›')}
                            </Link>
                        ) : (
                            <span key={index} className="text-muted-foreground">
                                {link.label
                                    .replace('&laquo;', '‹')
                                    .replace('&raquo;', '›')}
                            </span>
                        ),
                    )}
                </nav>
            </div>
        </>
    );
}
