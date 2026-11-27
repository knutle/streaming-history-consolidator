import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    Clock3,
    FileUp,
    Music2,
    Search,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index } from '@/routes/listens';
import { index as importsIndex } from '@/routes/imports';

type Props = {
    stats: {
        total: number;
        playedMs: number;
        unmatched: number;
        last30Days: number;
    };
    imports: {
        id: number;
        source: string;
        imported: number;
        duplicates: number;
        matched: number;
        created_at: string;
    }[];
};

export default function Dashboard({ stats, imports }: Props) {
    const cards = [
        {
            title: 'Total listens',
            value: stats.total.toLocaleString(),
            icon: Music2,
        },
        {
            title: 'Listening time',
            value: `${(stats.playedMs / 3_600_000).toFixed(1)} hours`,
            icon: Clock3,
        },
        {
            title: 'Last 30 days',
            value: stats.last30Days.toLocaleString(),
            icon: CalendarDays,
        },
        {
            title: 'Unmatched listens',
            value: stats.unmatched.toLocaleString(),
            icon: Search,
        },
    ];

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-10 px-5 py-8 sm:px-8 sm:py-12">
                <section className="grid items-center gap-8 rounded-3xl border bg-emerald-50 p-5 sm:p-8 lg:grid-cols-[1fr_auto] dark:bg-emerald-950/30">
                    <div className="max-w-2xl">
                        <p className="text-sm font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-400">
                            Your music, across services
                        </p>
                        <h1 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                            Your listening history, together.
                        </h1>
                        <p className="mt-4 leading-7 text-muted-foreground">
                            Explore your imports, connect catalog tracks, and
                            review recording matches in one place.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <Button asChild>
                                <Link href={importsIndex()}>
                                    <FileUp />
                                    Import history
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={index()}>
                                    Browse listening history
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </div>
                    </div>
                    <div className="min-w-52 rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Your collection
                        </p>
                        <p className="mt-3 text-3xl font-semibold tracking-tight">
                            {stats.total.toLocaleString()}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            listens in your history
                        </p>
                        <p className="mt-5 border-t pt-4 text-sm">
                            <span className="font-medium text-emerald-800 dark:text-emerald-300">
                                {stats.unmatched.toLocaleString()}
                            </span>{' '}
                            unmatched to review
                        </p>
                        <Link
                            href={index({ query: { match: 'unmatched' } })}
                            className="mt-3 flex w-fit items-center gap-1 font-medium text-emerald-800 underline underline-offset-4 dark:text-emerald-300"
                        >
                            Identify unmatched listens
                            <ArrowRight className="size-4" />
                        </Link>
                    </div>
                </section>

                <section className="flex flex-col gap-5">
                    <div>
                        <p className="text-sm font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-400">
                            Your overview
                        </p>
                        <h2 className="mt-2 text-2xl font-semibold tracking-tight">
                            A clearer picture of your listening
                        </h2>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {cards.map(({ icon: Icon, ...card }) => (
                            <article
                                key={card.title}
                                className="rounded-xl border bg-card p-5"
                            >
                                <Icon
                                    aria-hidden="true"
                                    className="size-5 text-emerald-700 dark:text-emerald-400"
                                />
                                <p className="mt-4 text-sm text-muted-foreground">
                                    {card.title}
                                </p>
                                <p className="mt-1 text-2xl font-semibold tracking-tight">
                                    {card.value}
                                </p>
                            </article>
                        ))}
                    </div>
                </section>

                <section className="rounded-xl border bg-card p-5 sm:p-6">
                    <h2 className="text-xl font-semibold tracking-tight">
                        Recent imports
                    </h2>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                        Import summaries are recorded from now on; earlier
                        imports are not listed.
                    </p>
                    {imports.length === 0 ? (
                        <p className="mt-4 rounded-lg bg-muted/40 p-4 text-sm text-muted-foreground">
                            No imports recorded yet. Upload your Spotify or
                            Apple Music export to get started.
                        </p>
                    ) : (
                        <ul className="mt-4 divide-y">
                            {imports.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap justify-between gap-2 py-4 text-sm"
                                >
                                    <span className="font-medium">
                                        {item.source === 'spotify'
                                            ? 'Spotify'
                                            : 'Apple Music'}{' '}
                                        · {item.created_at}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {item.imported} imported ·{' '}
                                        {item.duplicates} duplicates ·{' '}
                                        {item.matched} matched
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
