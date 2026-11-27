import { Head, Link, usePage } from '@inertiajs/react';
import Form from '@/components/form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as albumsIndex } from '@/routes/albums';
import { index as artistsIndex } from '@/routes/artists';
import { manage } from '@/routes/music-entities';

type Props = {
    type: 'artist' | 'album';
    entities: {
        data: {
            artist_name: string;
            release_name?: string;
            listen_id: number;
            listen_count: number;
            matched_count: number;
        }[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { search?: string; match?: string };
};

export default function MusicEntitiesIndex({ type, entities, filters }: Props) {
    const title = type === 'artist' ? 'Artists' : 'Albums';
    const route = type === 'artist' ? artistsIndex : albumsIndex;
    const { errors } = usePage().props;

    return (
        <>
            <Head title={title} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    <p className="text-sm text-muted-foreground">
                        Grouped by the exact imported artist credit
                        {type === 'album' ? ' and album name' : ''}. Catalog
                        links are managed separately from MusicBrainz status.
                    </p>
                </div>
                <section className="rounded-xl border p-4">
                    <Form
                        {...route.form()}
                        className="grid gap-3 sm:grid-cols-3"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="search">
                                Search {title.toLowerCase()}
                            </Label>
                            <Input
                                id="search"
                                name="search"
                                defaultValue={filters.search}
                            />
                            <InputError message={errors.search} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="match">MusicBrainz status</Label>
                            <select
                                id="match"
                                name="match"
                                defaultValue={filters.match ?? ''}
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                            >
                                <option value="">All</option>
                                <option value="matched">Matched</option>
                                <option value="unmatched">Unmatched</option>
                            </select>
                        </div>
                        <div className="flex items-end gap-2">
                            <Button>Apply</Button>
                            <Button variant="outline" asChild>
                                <Link href={route()}>Clear</Link>
                            </Button>
                        </div>
                    </Form>
                    <p className="mt-4 text-sm text-muted-foreground">
                        {entities.total} groups. Partially matched groups appear
                        in both filters.
                    </p>
                    <ul className="mt-3 divide-y">
                        {entities.data.map((entity) => {
                            const count = Number(entity.listen_count);
                            const matched = Number(entity.matched_count);
                            return (
                                <li
                                    key={entity.listen_id}
                                    className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                                >
                                    <div>
                                        <Link
                                            href={manage({
                                                type,
                                                listen: entity.listen_id,
                                            })}
                                            className="font-medium underline"
                                        >
                                            {type === 'artist'
                                                ? entity.artist_name
                                                : entity.release_name}
                                        </Link>
                                        {type === 'album' && (
                                            <p className="text-muted-foreground">
                                                {entity.artist_name}
                                            </p>
                                        )}
                                    </div>
                                    <span className="text-muted-foreground">
                                        {matched === 0
                                            ? 'Unmatched'
                                            : matched === count
                                              ? 'Matched'
                                              : 'Partially matched'}{' '}
                                        · {matched}/{count} listens matched
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                    {entities.data.length === 0 && (
                        <p className="py-4 text-sm text-muted-foreground">
                            No groups found.
                        </p>
                    )}
                    <nav
                        aria-label={`${title} pages`}
                        className="mt-4 flex flex-wrap gap-3 text-sm"
                    >
                        {entities.links.map((link, index) =>
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
