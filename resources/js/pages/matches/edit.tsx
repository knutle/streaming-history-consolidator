import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, index, update } from '@/routes/matches';

type Props = {
    id: number;
    saved: { source_track?: string | null; source_artist?: string | null };
    search: string;
    artist: string;
    page: number;
    hasMore: boolean;
    musicbrainzEnabled: boolean;
    suggestions: {
        recording_mbid: string;
        track_name: string;
        artist_name: string;
    }[];
};

export default function EditManualMatch({
    id,
    saved,
    search,
    artist,
    page,
    hasMore,
    musicbrainzEnabled,
    suggestions,
}: Props) {
    const { errors } = usePage().props;
    return (
        <>
            <Head title="Change manual match" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link href={index()} className="text-sm underline">
                    Back to manual matches
                </Link>
                <div>
                    <h1 className="text-2xl font-semibold">
                        Change future recording match
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {saved.source_track && saved.source_artist
                            ? `${saved.source_track} — ${saved.source_artist}. `
                            : ''}
                        Existing listens remain unchanged.
                    </p>
                </div>
                {!musicbrainzEnabled && (
                    <p className="text-sm text-amber-700 dark:text-amber-400">
                        Enable the local MusicBrainz database to choose a
                        recording.
                    </p>
                )}
                <Form
                    {...edit.form(id)}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="q">Track</Label>
                        <Input id="q" name="q" defaultValue={search} />
                        <InputError message={errors.q} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="artist">Artist</Label>
                        <Input
                            id="artist"
                            name="artist"
                            defaultValue={artist}
                        />
                        <InputError message={errors.artist} />
                    </div>
                    <Button>Search</Button>
                </Form>
                <InputError message={errors.recording_mbid} />
                <section className="flex flex-col gap-4">
                    {suggestions.map((candidate) => (
                        <div
                            key={`${candidate.recording_mbid}-${candidate.artist_name}`}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                        >
                            <div>
                                <p className="font-medium">
                                    {candidate.track_name}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {candidate.artist_name}
                                </p>
                            </div>
                            <Form {...update.form(id)}>
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="recording_mbid"
                                            value={candidate.recording_mbid}
                                        />
                                        <input
                                            type="hidden"
                                            name="track_name"
                                            value={candidate.track_name}
                                        />
                                        <input
                                            type="hidden"
                                            name="artist_name"
                                            value={candidate.artist_name}
                                        />
                                        <Button disabled={processing}>
                                            Use for future imports
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </div>
                    ))}
                    {suggestions.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No recordings found.
                        </p>
                    )}
                </section>
                <nav className="flex gap-4 text-sm">
                    {page > 1 && (
                        <Link
                            className="underline"
                            href={edit(id, {
                                query: { q: search, artist, page: page - 1 },
                            })}
                        >
                            Previous
                        </Link>
                    )}
                    {hasMore && (
                        <Link
                            className="underline"
                            href={edit(id, {
                                query: { q: search, artist, page: page + 1 },
                            })}
                        >
                            Next
                        </Link>
                    )}
                </nav>
            </div>
        </>
    );
}

EditManualMatch.layout = {
    breadcrumbs: [{ title: 'Manual matches', href: index() }],
};
