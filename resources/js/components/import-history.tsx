import { Form } from '@inertiajs/react';
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
        errors?: { file: string; message: string }[];
    } | null;
    canImport: boolean;
};

export default function ImportHistory({
    musicbrainzEnabled,
    importResult,
    canImport,
}: Props) {
    return (
        <section className="space-y-4">
            <Heading
                variant="small"
                title="Import history"
                description="Import your Spotify or Apple Music listening history"
            />
            <p className="text-sm text-muted-foreground">
                Select one or more files; the source is detected automatically.
                Upload Spotify extended audio history JSON files such as
                Streaming_History_Audio_2024-2025_0.json, or a Spotify export
                ZIP containing those files inside its export folder. Include
                YourLibrary.json and Playlist1.json (or their account-data ZIP)
                in the same upload to help identify tracks with missing Spotify
                URLs when the track, artist and album have one unambiguous
                match. Extended playback reasons and shuffle, skip and offline
                flags are retained; device and IP address data are not. Exact
                catalog IDs also record whether a track appeared in the uploaded
                library or playlists at export time, not necessarily when it was
                played.
            </p>
            <p className="text-sm text-muted-foreground">
                Apple imports accept Apple Music Play Activity.csv or a full
                Apple Media Services ZIP containing Apple_Media_Services.zip,
                which in turn contains the Play Activity CSV and optionally
                daily track history and library data. Re-imports skip previously
                processed events and can identify previously unmatched listens.
                Apple playback events are imported individually, not combined
                into sessions.
            </p>
            <p className="text-sm text-muted-foreground">
                Spotify account StreamingHistory_music files have less precise
                timestamps and cannot be safely deduplicated against extended
                history. Podcasts, video, technical logs, and unrelated account
                data are not imported. Unsupported files are reported below.
            </p>
            {!musicbrainzEnabled && (
                <p className="text-sm text-amber-700 dark:text-amber-400">
                    MusicBrainz matching is disabled. Run{' '}
                    <code>php artisan musicbrainz:setup</code> locally or
                    configure an existing database before importing.
                </p>
            )}
            {importResult && (
                <div role="status" className="space-y-2 text-sm">
                    <p>
                        Imported {importResult.imported}; already imported{' '}
                        {importResult.duplicates}; matched{' '}
                        {importResult.matched}. Each history file is imported
                        independently; a failed file is rolled back.
                    </p>
                    {!!importResult.errors?.length && (
                        <ul className="list-disc space-y-1 pl-5 text-destructive">
                            {importResult.errors.map((error, index) => (
                                <li key={index}>
                                    {error.file}: {error.message}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            {canImport ? (
                <Form
                    {...importMethod.form()}
                    encType="multipart/form-data"
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ errors, processing, progress }) => (
                        <>
                            <div className="grid min-w-64 flex-1 gap-2">
                                <Label htmlFor="file">
                                    History and metadata files
                                </Label>
                                <Input
                                    id="file"
                                    name="files[]"
                                    type="file"
                                    accept=".json,.csv,.zip,application/json,text/csv,application/zip"
                                    multiple
                                    required
                                />
                                <InputError message={errors.files} />
                                {Object.entries(errors)
                                    .filter(([key]) => key.startsWith('files.'))
                                    .map(([key, message]) => (
                                        <InputError
                                            key={key}
                                            message={message}
                                        />
                                    ))}
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
                Up to 20 files, maximum 1 GB each and 100 supported ZIP entries
                per upload, subject to your server’s PHP upload limits. Spotify
                library and playlist files are limited to 8 MB each. Timestamp
                values without a timezone are interpreted as UTC. Only music
                events with positive playback duration are imported. MusicBrainz
                artist aliases require the full database; the canonical CSV does
                not contain aliases. Imports allow at least two hours of PHP
                execution time; web server and proxy timeouts may still apply.
            </p>
        </section>
    );
}
