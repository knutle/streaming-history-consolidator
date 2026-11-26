import { Head } from '@inertiajs/react';
import DeleteListeningHistory from '@/components/delete-listening-history';
import ImportHistory from '@/components/import-history';
import { create } from '@/routes/listens';

type Props = {
    musicbrainzEnabled: boolean;
    importResult: {
        imported: number;
        duplicates: number;
        matched: number;
    } | null;
    canImport: boolean;
};

export default function ImportHistoryPage(props: Props) {
    return (
        <>
            <Head title="Import history" />
            <h1 className="sr-only">Import history</h1>
            <ImportHistory {...props} />
            <DeleteListeningHistory />
        </>
    );
}

ImportHistoryPage.layout = {
    breadcrumbs: [{ title: 'Import history', href: create() }],
};
