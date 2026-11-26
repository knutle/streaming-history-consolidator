import { Form, Head, Link, usePoll } from '@inertiajs/react';
import ImportHistory from '@/components/import-history';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { abort, index, retry } from '@/routes/imports';
import { destroy as deleteFiles } from '@/routes/imports/files';

type ImportRun = {
    id: string;
    status:
        | 'queued'
        | 'running'
        | 'aborting'
        | 'aborted'
        | 'completed'
        | 'completed_with_errors'
        | 'failed';
    created_at: string;
    error: string | null;
    has_files: boolean;
    can_abort: boolean;
    result: {
        imported: number;
        duplicates: number;
        matched: number;
        errors: { file: string; message: string }[];
    } | null;
    progress: {
        file: string;
        current: number;
        total: number;
        processed: number;
    } | null;
};

type Props = {
    imports: {
        data: ImportRun[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    musicbrainzEnabled: boolean;
    canImport: boolean;
    started: boolean;
};

export default function Imports({
    imports,
    musicbrainzEnabled,
    canImport,
    started,
}: Props) {
    usePoll(3000, { only: ['imports'] });

    return (
        <>
            <Head title="Imports" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-semibold">Imports</h1>
                {started && (
                    <p role="status" className="text-sm">
                        Your import has been queued. You can leave this page and
                        return to check its progress.
                    </p>
                )}
                <section className="rounded-xl border p-5">
                    <ImportHistory
                        musicbrainzEnabled={musicbrainzEnabled}
                        canImport={canImport}
                    />
                </section>
                <section className="rounded-xl border p-5">
                    <h2 className="text-lg font-semibold">Import progress</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Progress updates automatically. Processed counts are
                        provisional until each file finishes. If an import
                        remains queued, ensure the imports queue worker is
                        running:{' '}
                        <code>
                            php artisan queue:work imports --queue=imports
                            --timeout=7200 --tries=1
                        </code>
                        . The web server and worker must share the private local
                        storage and file cache. With SQLite, other database
                        writes may wait until the current file finishes.
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Uploaded files are kept privately for retries until you
                        delete them or delete your account. Older imports whose
                        files were removed must be uploaded again.
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        To recover stranded running imports, first stop all
                        import workers and disable automatic restarts on every
                        host, then run{' '}
                        <code>
                            php artisan imports:abort-running --workers-stopped
                        </code>
                        . This affects all users, retains uploads and completed
                        listens, and leaves queued imports unchanged.
                    </p>
                    {imports.data.length === 0 ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            No imports yet.
                        </p>
                    ) : (
                        <ul className="mt-3 divide-y">
                            {imports.data.map((item) => (
                                <li
                                    key={item.id}
                                    className="space-y-2 py-4 text-sm"
                                >
                                    <p className="font-medium">
                                        {item.created_at} ·{' '}
                                        {item.status.replaceAll('_', ' ')}
                                    </p>
                                    {item.status === 'running' &&
                                        !item.progress && (
                                            <p>Preparing files and metadata…</p>
                                        )}
                                    {item.status === 'running' &&
                                        item.progress && (
                                            <p role="status">
                                                File {item.progress.current} of{' '}
                                                {item.progress.total}:{' '}
                                                {item.progress.file} ·{' '}
                                                {item.progress.processed.toLocaleString()}{' '}
                                                records processed
                                            </p>
                                        )}
                                    {item.result && (
                                        <>
                                            <p>
                                                {item.result.imported} imported
                                                · {item.result.duplicates}{' '}
                                                duplicates ·{' '}
                                                {item.result.matched} matched
                                            </p>
                                            {item.result.errors.length > 0 && (
                                                <ul className="list-disc space-y-1 pl-5 text-destructive">
                                                    {item.result.errors.map(
                                                        (error, i) => (
                                                            <li key={i}>
                                                                {error.file}:{' '}
                                                                {error.message}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </>
                                    )}
                                    {item.error && (
                                        <p className="text-destructive">
                                            {item.error}
                                        </p>
                                    )}
                                    {item.status === 'aborting' && (
                                        <p role="status">
                                            Stopping at the next safe
                                            checkpoint…
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {item.status === 'running' &&
                                            !item.can_abort && (
                                                <p className="text-muted-foreground">
                                                    This import cannot be
                                                    stopped by its current
                                                    worker.
                                                </p>
                                            )}
                                        {item.status === 'running' &&
                                            item.can_abort && (
                                                <Form
                                                    {...abort.form(item.id)}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            {processing
                                                                ? 'Stopping…'
                                                                : 'Stop import'}
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                        {[
                                            'completed',
                                            'completed_with_errors',
                                            'failed',
                                            'aborted',
                                        ].includes(item.status) && (
                                            <>
                                                {item.has_files &&
                                                    canImport && (
                                                        <Form
                                                            {...retry.form(
                                                                item.id,
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                                errors,
                                                            }) => (
                                                                <>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                    >
                                                                        {processing
                                                                            ? 'Queueing…'
                                                                            : 'Retry import'}
                                                                    </Button>
                                                                    <InputError
                                                                        message={
                                                                            errors.import
                                                                        }
                                                                    />
                                                                </>
                                                            )}
                                                        </Form>
                                                    )}
                                                {!item.has_files && (
                                                    <p className="text-muted-foreground">
                                                        Original files are
                                                        unavailable; upload them
                                                        again to retry.
                                                    </p>
                                                )}
                                                {item.has_files && (
                                                    <Dialog>
                                                        <DialogTrigger asChild>
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                            >
                                                                Delete uploaded
                                                                files
                                                            </Button>
                                                        </DialogTrigger>
                                                        <DialogContent>
                                                            <DialogTitle>
                                                                Delete uploaded
                                                                files?
                                                            </DialogTitle>
                                                            <DialogDescription>
                                                                The imported
                                                                listens remain,
                                                                but you will
                                                                need to upload
                                                                these files
                                                                again to retry.
                                                            </DialogDescription>
                                                            <Form
                                                                {...deleteFiles.form(
                                                                    item.id,
                                                                )}
                                                                options={{
                                                                    preserveScroll: true,
                                                                }}
                                                            >
                                                                {({
                                                                    processing,
                                                                }) => (
                                                                    <DialogFooter className="gap-2">
                                                                        <DialogClose
                                                                            asChild
                                                                        >
                                                                            <Button
                                                                                type="button"
                                                                                variant="secondary"
                                                                                disabled={
                                                                                    processing
                                                                                }
                                                                            >
                                                                                Cancel
                                                                            </Button>
                                                                        </DialogClose>
                                                                        <Button
                                                                            type="submit"
                                                                            variant="destructive"
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                        >
                                                                            Delete
                                                                            files
                                                                        </Button>
                                                                    </DialogFooter>
                                                                )}
                                                            </Form>
                                                        </DialogContent>
                                                    </Dialog>
                                                )}
                                            </>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    <nav
                        className="mt-4 flex flex-wrap gap-2"
                        aria-label="Import pages"
                    >
                        {imports.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    className={
                                        link.active
                                            ? 'font-semibold underline'
                                            : 'underline'
                                    }
                                >
                                    {link.label
                                        .replace('&laquo;', '‹')
                                        .replace('&raquo;', '›')}
                                </Link>
                            ) : (
                                <span key={i} className="text-muted-foreground">
                                    {link.label
                                        .replace('&laquo;', '‹')
                                        .replace('&raquo;', '›')}
                                </span>
                            ),
                        )}
                    </nav>
                </section>
            </div>
        </>
    );
}

Imports.layout = {
    breadcrumbs: [{ title: 'Imports', href: index() }],
};
