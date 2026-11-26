import { Form } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { importMethod } from '@/routes/listens';

type Props = {
    musicbrainzEnabled: boolean;
    importResult: {
        imported: number;
        duplicates: number;
        matched: number;
    } | null;
    canImport: boolean;
};

export default function ImportHistory({
    musicbrainzEnabled,
    importResult,
    canImport,
}: Props) {
    const [source, setSource] = useState('spotify');

    return (
        <section className="space-y-4">
            <Heading
                variant="small"
                title="Import history"
                description="Import your Spotify or Apple Music listening history"
            />
            <p className="text-sm text-muted-foreground">
                Upload Spotify extended history, an Apple Music Play Activity
                CSV, or a full Apple Media Services ZIP. ZIP imports use
                unambiguous library and daily track metadata to enrich plays.
                Re-imports skip previously processed events and can identify
                previously unmatched listens. Apple playback events are imported
                individually, not combined into sessions.
            </p>
            {!musicbrainzEnabled && (
                <p className="text-sm text-amber-700 dark:text-amber-400">
                    MusicBrainz matching is disabled. Run{' '}
                    <code>php artisan musicbrainz:setup</code> locally or
                    configure an existing database before importing.
                </p>
            )}
            {importResult && (
                <p role="status" className="text-sm">
                    Imported {importResult.imported}; already imported{' '}
                    {importResult.duplicates}; matched {importResult.matched}.
                </p>
            )}
            {canImport ? (
                <Form
                    {...importMethod.form()}
                    encType="multipart/form-data"
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ errors, processing, progress }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="import-source">Source</Label>
                                <select
                                    id="import-source"
                                    name="source"
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                                    value={source}
                                    onChange={(event) =>
                                        setSource(event.target.value)
                                    }
                                >
                                    <option value="spotify">Spotify</option>
                                    <option value="apple">
                                        Apple Music CSV
                                    </option>
                                    <option value="apple_zip">
                                        Apple Media Services ZIP
                                    </option>
                                </select>
                                <InputError message={errors.source} />
                            </div>
                            <div className="grid min-w-64 flex-1 gap-2">
                                <Label htmlFor="file">History file</Label>
                                <Input
                                    key={source}
                                    id="file"
                                    name="file"
                                    type="file"
                                    accept={
                                        source === 'spotify'
                                            ? '.json,application/json'
                                            : source === 'apple'
                                              ? '.csv,text/csv'
                                              : '.zip,application/zip'
                                    }
                                    required
                                />
                                <InputError message={errors.file} />
                            </div>
                            <Button disabled={processing}>
                                {processing ? 'Importing…' : 'Import'}
                            </Button>
                            {progress && (
                                <span className="text-sm">
                                    Uploading {progress.percentage}%
                                </span>
                            )}
                        </>
                    )}
                </Form>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Verify your email address to import listening history.
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                Maximum file size is 1 GB, subject to your server’s PHP upload
                limits. Timestamp values without a timezone are interpreted as
                UTC. Only music events with positive playback duration are
                imported. MusicBrainz artist aliases require the full database;
                the canonical CSV does not contain aliases.
            </p>
        </section>
    );
}
