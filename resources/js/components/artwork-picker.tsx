import { router, useHttp } from '@inertiajs/react';
import { ImagePlus } from 'lucide-react';
import { useState } from 'react';
import Form from '@/components/form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, search, store } from '@/routes/artwork';

type Results = {
    results: { mbid: string; name: string; context: string | null }[];
    hasMore: boolean;
};

export default function ArtworkPicker({
    listenId,
    album,
    result,
}: {
    listenId: number;
    album: string;
    result?: string | null;
}) {
    const request = useHttp<{ q: string; page: number }, Results>({
        q: album,
        page: 1,
    });
    const [results, setResults] = useState<Results | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const [submittedQuery, setSubmittedQuery] = useState('');
    const [page, setPage] = useState(1);

    async function find(query: string, nextPage = 1) {
        request.setData({ q: query, page: nextPage });
        setFailure(null);
        setResults(null);
        try {
            await request.submit(search(listenId), {
                onSuccess: (data) => {
                    setResults(data);
                    setPage(nextPage);
                    setSubmittedQuery(query);
                },
                onError: (errors) =>
                    setFailure(
                        errors.musicbrainz_api ??
                            errors.q ??
                            'Artwork search failed. Please try again.',
                    ),
            });
        } catch {
            setFailure(
                'Could not complete the artwork search. Please try again.',
            );
        }
    }

    function refreshCover() {
        router.reload({ only: ['artwork'] });
    }

    return (
        <details className="rounded-xl border bg-card p-4 text-sm">
            <summary className="cursor-pointer font-medium">
                <ImagePlus
                    aria-hidden="true"
                    className="mr-2 inline size-4 text-emerald-700 dark:text-emerald-400"
                />
                Find or change album artwork
            </summary>
            <div className="mt-4 flex flex-col gap-4">
                <p className="max-w-3xl text-muted-foreground">
                    Searching sends the entered album name to MusicBrainz.
                    Choose a release to cache its cover from Cover Art Archive.
                    This changes artwork only, not your music matches.
                </p>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void find(request.data.q);
                    }}
                >
                    <div className="grid min-w-0 flex-1 gap-2">
                        <Label htmlFor={`artwork-query-${listenId}`}>
                            Album search
                        </Label>
                        <Input
                            id={`artwork-query-${listenId}`}
                            value={request.data.q}
                            onChange={(event) =>
                                request.setData('q', event.target.value)
                            }
                            required
                            maxLength={255}
                        />
                    </div>
                    <Button disabled={request.processing} variant="outline">
                        {request.processing
                            ? 'Searching…'
                            : 'Search MusicBrainz'}
                    </Button>
                </form>
                <InputError message={failure ?? undefined} />
                {results && (
                    <>
                        <p className="text-muted-foreground">
                            Results for “{submittedQuery}” · Page {page}
                        </p>
                        {results.results.length === 0 && (
                            <p>
                                No releases found. Try a different album name.
                            </p>
                        )}
                        <ul className="divide-y">
                            {results.results.map((release) => (
                                <li
                                    key={release.mbid}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0 flex-1 [overflow-wrap:anywhere]">
                                        <p className="font-medium">
                                            {release.name}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {release.context ??
                                                'Release details unavailable'}
                                        </p>
                                    </div>
                                    <Form
                                        {...store.form(listenId)}
                                        errorBag="artwork"
                                        onSuccess={refreshCover}
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="mbid"
                                                    value={release.mbid}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="name"
                                                    value={release.name}
                                                />
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    Use artwork
                                                </Button>
                                                <InputError
                                                    message={
                                                        errors.mbid ??
                                                        errors.name
                                                    }
                                                />
                                            </>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                        <nav
                            aria-label="Artwork search pages"
                            className="flex gap-2"
                        >
                            {page > 1 && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={request.processing}
                                    onClick={() =>
                                        void find(submittedQuery, page - 1)
                                    }
                                >
                                    Previous
                                </Button>
                            )}
                            {results.hasMore && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={request.processing}
                                    onClick={() =>
                                        void find(submittedQuery, page + 1)
                                    }
                                >
                                    Next
                                </Button>
                            )}
                        </nav>
                    </>
                )}
                <Form {...destroy.form(listenId)} onSuccess={refreshCover}>
                    {({ processing, errors }) => (
                        <>
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={processing}
                            >
                                Reset artwork choice
                            </Button>
                            <InputError message={errors.artwork} />
                        </>
                    )}
                </Form>
                {result && <p role="status">{result}</p>}
            </div>
        </details>
    );
}
