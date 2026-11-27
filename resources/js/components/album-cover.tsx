import { Deferred } from '@inertiajs/react';
import { Disc3, Users } from 'lucide-react';
import { useState } from 'react';

export type Artwork = {
    status:
        | 'disabled'
        | 'unavailable'
        | 'ready'
        | 'missing'
        | 'stale'
        | 'error';
    mbid: string | null;
    url: string | null;
    error: string | null;
};

export type ArtworkProps = {
    artworkEnabled: boolean;
    artwork?: Artwork | null;
    artworkResult?: string | null;
};

export default function AlbumCover({
    artworkEnabled,
    artwork,
    title,
    artist = false,
}: ArtworkProps & { title: string; artist?: boolean }) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const Icon = artist ? Users : Disc3;
    const placeholder = (
        <div className="flex size-20 shrink-0 items-center justify-center rounded-xl border bg-card/80 text-emerald-700 sm:size-36 dark:text-emerald-400">
            <Icon aria-hidden="true" className="size-10 stroke-1 sm:size-12" />
        </div>
    );

    if (!artworkEnabled || artist) return placeholder;

    return (
        <Deferred
            data="artwork"
            fallback={
                <div
                    className="size-20 shrink-0 animate-pulse rounded-xl bg-muted sm:size-36"
                    role="status"
                    aria-label="Loading album artwork"
                />
            }
        >
            <figure className="flex w-20 shrink-0 flex-col gap-2 sm:w-36">
                {artwork?.url && failedUrl !== artwork.url ? (
                    <img
                        src={artwork.url}
                        alt={`Album cover for ${title}`}
                        width={144}
                        height={144}
                        className="aspect-square w-full rounded-xl border object-contain shadow-sm"
                        onError={() => setFailedUrl(artwork.url)}
                    />
                ) : (
                    placeholder
                )}
                <figcaption className="text-xs leading-4 text-muted-foreground">
                    {artwork?.url && failedUrl !== artwork.url && (
                        <a
                            href={`https://coverartarchive.org/release/${artwork.mbid}`}
                            target="_blank"
                            rel="noreferrer"
                            className="underline underline-offset-2"
                        >
                            Cover Art Archive
                        </a>
                    )}
                    {failedUrl && failedUrl === artwork?.url ? (
                        <p role="status">Could not display the cached cover.</p>
                    ) : artwork?.error ? (
                        <p role="status">{artwork.error}</p>
                    ) : !artwork?.url ? (
                        <span>
                            {artwork?.status === 'missing'
                                ? 'No cover available.'
                                : 'No album cover selected.'}
                        </span>
                    ) : null}
                </figcaption>
            </figure>
        </Deferred>
    );
}
