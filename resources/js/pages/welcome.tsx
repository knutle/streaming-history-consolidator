import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    AudioLines,
    Check,
    Disc3,
    FileUp,
    GitMerge,
    Library,
    Search,
    ShieldCheck,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { appName } from '@/lib/app-name';
import { dashboard, login, register } from '@/routes';

const features = [
    {
        icon: FileUp,
        title: 'Bring your history together',
        description:
            'Import Spotify extended streaming history and Apple Music play activity, then browse both in one timeline.',
    },
    {
        icon: Search,
        title: 'Identify the music',
        description:
            'Find recordings, artists, and albums using MusicBrainz. Review uncertain results before applying them.',
    },
    {
        icon: GitMerge,
        title: 'Connect across catalogs',
        description:
            'Link Spotify and Apple Music catalog tracks that represent the same recording without merging their plays.',
    },
    {
        icon: Library,
        title: 'Keep exploring',
        description:
            'Filter your listens, explore artist and album groups, and revisit saved matching choices.',
    },
];

export default function Welcome() {
    const { auth, canRegister } = usePage().props;

    return (
        <>
            <Head title="Your listening history, together" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-5 sm:px-8">
                        <a
                            href="#top"
                            aria-label={appName}
                            className="flex items-center gap-2 font-semibold tracking-tight"
                        >
                            <span className="flex size-9 items-center justify-center rounded-lg bg-emerald-700 text-white dark:bg-emerald-500 dark:text-emerald-950">
                                <AudioLines
                                    aria-hidden="true"
                                    className="size-5"
                                />
                            </span>
                            <span>{appName}</span>
                        </a>
                        <nav
                            aria-label="Account"
                            className="flex items-center gap-2"
                        >
                            {auth.user ? (
                                <Button asChild size="sm">
                                    <Link href={dashboard()}>Dashboard</Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                    {canRegister && (
                                        <Button asChild size="sm">
                                            <Link href={register()}>
                                                Register
                                            </Link>
                                        </Button>
                                    )}
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main id="top">
                    <section className="mx-auto grid max-w-6xl grid-cols-1 items-center gap-12 px-5 py-16 sm:px-8 lg:grid-cols-2 lg:gap-16 lg:py-24">
                        <div>
                            <p className="text-sm font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-400">
                                Your music, across services
                            </p>
                            <h1 className="mt-4 max-w-xl text-4xl font-semibold tracking-tight sm:text-5xl lg:text-6xl">
                                Your listening history, together.
                            </h1>
                            <p className="mt-6 max-w-lg text-lg leading-8 text-muted-foreground">
                                Bring your Spotify and Apple Music exports into
                                one place. Explore your listening, identify
                                recordings, and connect the music you know
                                across catalogs.
                            </p>
                            <div className="mt-8 flex flex-wrap items-center gap-4">
                                <Button asChild size="lg">
                                    {auth.user ? (
                                        <Link href={dashboard()}>
                                            Go to dashboard <ArrowRight />
                                        </Link>
                                    ) : canRegister ? (
                                        <Link href={register()}>
                                            Get started <ArrowRight />
                                        </Link>
                                    ) : (
                                        <Link href={login()}>
                                            Log in <ArrowRight />
                                        </Link>
                                    )}
                                </Button>
                                <a
                                    href="#guide"
                                    className="text-sm font-medium underline underline-offset-4"
                                >
                                    Read the guide
                                </a>
                            </div>
                        </div>

                        <div className="rounded-3xl border bg-emerald-50 p-5 sm:p-8 dark:bg-emerald-950/30">
                            <div className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Illustrative example
                                        </p>
                                        <p className="mt-1 text-lg font-semibold">
                                            One recording, two catalogs
                                        </p>
                                    </div>
                                    <Disc3
                                        aria-hidden="true"
                                        className="size-9 shrink-0 text-emerald-700 dark:text-emerald-400"
                                    />
                                </div>
                                <div className="mt-6 space-y-3">
                                    {['Spotify', 'Apple Music'].map(
                                        (source) => (
                                            <div
                                                key={source}
                                                className="flex items-center gap-3 rounded-xl border p-4"
                                            >
                                                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                                                    <AudioLines
                                                        aria-hidden="true"
                                                        className="size-5 text-muted-foreground"
                                                    />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-medium">
                                                        Northbound
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        The Example Band
                                                    </p>
                                                </div>
                                                <span className="text-xs font-medium text-muted-foreground">
                                                    {source}
                                                </span>
                                            </div>
                                        ),
                                    )}
                                </div>
                                <p className="mt-5 flex items-start gap-2 text-sm text-muted-foreground">
                                    <Check
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0 text-emerald-700 dark:text-emerald-400"
                                    />
                                    Catalog tracks can be linked; each listening
                                    event remains separate.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section className="border-y bg-muted/40">
                        <div className="mx-auto max-w-6xl px-5 py-16 sm:px-8">
                            <p className="text-sm font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-400">
                                What you can do
                            </p>
                            <h2 className="mt-3 text-3xl font-semibold tracking-tight">
                                A clearer picture of your listening
                            </h2>
                            <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {features.map(
                                    ({ icon: Icon, title, description }) => (
                                        <article
                                            key={title}
                                            className="rounded-xl border bg-card p-5"
                                        >
                                            <Icon
                                                aria-hidden="true"
                                                className="size-5 text-emerald-700 dark:text-emerald-400"
                                            />
                                            <h3 className="mt-4 font-semibold">
                                                {title}
                                            </h3>
                                            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                                {description}
                                            </p>
                                        </article>
                                    ),
                                )}
                            </div>
                        </div>
                    </section>

                    <section
                        id="guide"
                        className="mx-auto max-w-6xl scroll-mt-8 px-5 py-16 sm:px-8 lg:py-20"
                    >
                        <p className="text-sm font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-400">
                            Getting started
                        </p>
                        <h2 className="mt-3 text-3xl font-semibold tracking-tight">
                            From export to collection
                        </h2>
                        <p className="mt-3 max-w-2xl leading-7 text-muted-foreground">
                            Start with your own data exports, then improve
                            matches at your own pace. The app does not need your
                            Spotify or Apple Music password.
                        </p>
                        <div className="mt-8 grid gap-4 lg:grid-cols-3">
                            <article className="rounded-xl border p-6">
                                <p className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                    01 / Export
                                </p>
                                <h3 className="mt-3 text-lg font-semibold">
                                    Get your files
                                </h3>
                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                    Request Spotify extended streaming history
                                    or your Apple Music data export. Spotify
                                    extended audio JSON and Apple Music Play
                                    Activity CSV files are supported, as are
                                    supported export ZIPs.
                                </p>
                            </article>
                            <article className="rounded-xl border p-6">
                                <p className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                    02 / Import
                                </p>
                                <h3 className="mt-3 text-lg font-semibold">
                                    Upload and follow progress
                                </h3>
                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                    Create an account, verify your email, and
                                    upload your files on the Imports page.
                                    Imports run in the background; you can
                                    return to check progress or retry.
                                </p>
                            </article>
                            <article className="rounded-xl border p-6">
                                <p className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                    03 / Explore
                                </p>
                                <h3 className="mt-3 text-lg font-semibold">
                                    Browse and identify
                                </h3>
                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                    Filter your timeline, open an unmatched
                                    listen, or select multiple listens for a
                                    MusicBrainz search. Review candidates and
                                    inspect the automatic match summary.
                                </p>
                            </article>
                        </div>

                        <div className="mt-12 grid gap-10 lg:grid-cols-2 lg:gap-16">
                            <div>
                                <h3 className="text-xl font-semibold">
                                    Import notes
                                </h3>
                                <ul className="mt-4 list-disc space-y-3 pl-5 text-sm leading-6 text-muted-foreground">
                                    <li>
                                        For example, upload a Spotify{' '}
                                        <code>
                                            Streaming_History_Audio_2024-2025_0.json
                                        </code>{' '}
                                        file or an Apple{' '}
                                        <code>
                                            Apple Music Play Activity.csv
                                        </code>{' '}
                                        file. Sources are detected
                                        automatically.
                                    </li>
                                    <li>
                                        Spotify library and playlist JSON files
                                        can accompany history to help identify
                                        missing track URLs. The shorter Spotify{' '}
                                        <code>StreamingHistory_music</code>{' '}
                                        files cannot be safely deduplicated and
                                        are not supported.
                                    </li>
                                    <li>
                                        Re-imports skip previously imported
                                        events and can help identify unmatched
                                        listens. Each history file is processed
                                        independently.
                                    </li>
                                    <li>
                                        You can upload up to 20 files, each up
                                        to 1 GB, subject to server limits. Only
                                        music events with positive playback
                                        duration are imported.
                                    </li>
                                </ul>
                            </div>
                            <div>
                                <h3 className="text-xl font-semibold">
                                    Matching and review
                                </h3>
                                <ul className="mt-4 list-disc space-y-3 pl-5 text-sm leading-6 text-muted-foreground">
                                    <li>
                                        Import-time automatic matching uses a
                                        configured local MusicBrainz database.
                                        Online searches are explicit actions.
                                    </li>
                                    <li>
                                        Bulk matching sends selected track,
                                        artist, and album names to MusicBrainz.
                                        Unique exact title and artist matches
                                        may be applied even if the album is not
                                        found; ambiguous results can be
                                        reviewed.
                                    </li>
                                    <li>
                                        For example, if two recordings share a
                                        title and artist, compare the candidates
                                        before approving one. Existing matches
                                        and conflicting saved choices are not
                                        overwritten by bulk matching.
                                    </li>
                                    <li>
                                        Manually chosen recording matches are
                                        remembered for future imports. Linked
                                        catalog recordings may also update
                                        unmatched listens.
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div className="mt-12 flex items-start gap-4 rounded-xl border bg-muted/40 p-6">
                            <ShieldCheck
                                aria-hidden="true"
                                className="mt-0.5 size-5 shrink-0 text-emerald-700 dark:text-emerald-400"
                            />
                            <div>
                                <h3 className="font-semibold">
                                    Your files and listening data
                                </h3>
                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                    Listening history is scoped to your account.
                                    Uploaded files are kept privately for
                                    retries until you delete them or your
                                    account. Spotify device and IP address data
                                    are not imported. MusicBrainz API searches
                                    send the relevant music metadata when you
                                    choose to use them.
                                </p>
                            </div>
                        </div>
                    </section>
                </main>
                <footer className="border-t">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-5 py-6 text-sm text-muted-foreground sm:px-8">
                        <p>{appName}</p>
                        <a
                            href="#guide"
                            className="underline underline-offset-4"
                        >
                            Getting started guide
                        </a>
                    </div>
                </footer>
            </div>
        </>
    );
}
