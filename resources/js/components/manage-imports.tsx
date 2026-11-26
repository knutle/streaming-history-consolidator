import { Form } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
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
import { abort, destroy } from '@/routes/imports/bulk';

type Props = {
    cancelRequested: number | null;
    cancelUnavailable: number;
    deletedImports: number | null;
};

export default function ManageImports({
    cancelRequested,
    cancelUnavailable,
    deletedImports,
}: Props) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Manage imports"
                description="Cancel and delete active or finished imports"
            />
            <div className="space-y-4 rounded-lg border p-4">
                <p className="text-sm text-muted-foreground">
                    Stopping an import rolls back the file being processed;
                    previously completed files and imported listens remain.
                    Cancelled import records and uploads are deleted after they
                    stop. Queued imports stop when the worker picks them up.
                </p>
                <div className="flex flex-wrap gap-2">
                    <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline">
                                Cancel and delete active imports
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                Cancel and delete all active imports?
                            </DialogTitle>
                            <DialogDescription>
                                This requests cancellation of your queued and
                                running imports. Running imports stop at a safe
                                checkpoint. Imports from an older worker may not
                                support stopping. Stopped records and uploaded
                                files are deleted; imported listens remain.
                            </DialogDescription>
                            <Form
                                {...abort.form()}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setCancelOpen(false)}
                            >
                                {({ processing }) => (
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                disabled={processing}
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing
                                                ? 'Requesting…'
                                                : 'Cancel and delete imports'}
                                        </Button>
                                    </DialogFooter>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                    <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                        <DialogTrigger asChild>
                            <Button variant="destructive">
                                Delete finished imports
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                Delete all finished imports?
                            </DialogTitle>
                            <DialogDescription>
                                Completed, failed and stopped import records and
                                their uploaded files will be permanently
                                deleted. Imported listens will remain. Active
                                imports must stop before their records can be
                                deleted.
                            </DialogDescription>
                            <Form
                                {...destroy.form()}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setDeleteOpen(false)}
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <InputError message={errors.imports} />
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    disabled={processing}
                                                >
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                {processing
                                                    ? 'Deleting…'
                                                    : 'Delete finished imports'}
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>
                {cancelRequested !== null && (
                    <p role="status" className="text-sm">
                        Cancellation requested for {cancelRequested} imports.
                        {cancelUnavailable > 0 && (
                            <>
                                {' '}
                                {cancelUnavailable} could not be stopped because
                                their worker does not support cancellation or
                                they are being updated. Check the Imports page
                                and try again later.
                            </>
                        )}
                    </p>
                )}
                {deletedImports !== null && (
                    <p role="status" className="text-sm">
                        Deleted {deletedImports} finished imports.
                    </p>
                )}
            </div>
        </div>
    );
}
