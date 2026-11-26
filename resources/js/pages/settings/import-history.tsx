import { Head, Link } from '@inertiajs/react';
import DeleteListeningHistory from '@/components/delete-listening-history';
import { ImportSummary, type ImportResult } from '@/components/import-history';
import ManageImports from '@/components/manage-imports';
import { index as importsIndex } from '@/routes/imports';
import { create } from '@/routes/listens';

export default function ImportHistoryPage({
    importResult,
    cancelRequested,
    cancelUnavailable,
    deletedImports,
}: {
    importResult: ImportResult | null;
    cancelRequested: number | null;
    cancelUnavailable: number;
    deletedImports: number | null;
}) {
    return (
        <>
            <Head title="Import history" />
            <h1 className="sr-only">Import history</h1>
            <p className="text-sm">
                <Link className="underline" href={importsIndex()}>
                    Upload files and track imports
                </Link>
            </p>
            {importResult && <ImportSummary result={importResult} />}
            <ManageImports
                cancelRequested={cancelRequested}
                cancelUnavailable={cancelUnavailable}
                deletedImports={deletedImports}
            />
            <DeleteListeningHistory />
        </>
    );
}

ImportHistoryPage.layout = {
    breadcrumbs: [{ title: 'Import history', href: create() }],
};
