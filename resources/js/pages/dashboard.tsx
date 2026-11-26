import { Head, Link } from '@inertiajs/react';
import { dashboard } from '@/routes';
import { index } from '@/routes/listens';
import { edit } from '@/routes/profile';

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
        { title: 'Total listens', value: stats.total.toLocaleString() },
        {
            title: 'Listening time',
            value: `${(stats.playedMs / 3_600_000).toFixed(1)} hours`,
        },
        { title: 'Last 30 days', value: stats.last30Days.toLocaleString() },
        { title: 'Unmatched listens', value: stats.unmatched.toLocaleString() },
    ];

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-semibold">Dashboard</h1>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {cards.map((card) => (
                        <div key={card.title} className="rounded-xl border p-5">
                            <p className="text-sm text-muted-foreground">
                                {card.title}
                            </p>
                            <p className="mt-2 text-2xl font-semibold">
                                {card.value}
                            </p>
                        </div>
                    ))}
                </div>
                <div className="flex flex-wrap gap-4 text-sm">
                    <Link href={index()} className="underline">
                        Browse listens
                    </Link>
                    <Link
                        href={index({ query: { match: 'unmatched' } })}
                        className="underline"
                    >
                        Identify unmatched listens
                    </Link>
                    <Link href={edit()} className="underline">
                        Import history
                    </Link>
                </div>
                <section className="rounded-xl border p-5">
                    <h2 className="text-lg font-semibold">Recent imports</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Import summaries are recorded from now on; earlier
                        imports are not listed.
                    </p>
                    {imports.length === 0 ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            No imports recorded yet.
                        </p>
                    ) : (
                        <ul className="mt-3 divide-y">
                            {imports.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap justify-between gap-2 py-3 text-sm"
                                >
                                    <span>
                                        {item.source === 'spotify'
                                            ? 'Spotify'
                                            : 'Apple Music'}{' '}
                                        · {item.created_at}
                                    </span>
                                    <span>
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
