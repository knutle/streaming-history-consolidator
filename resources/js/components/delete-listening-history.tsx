import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { destroy } from '@/actions/App/Http/Controllers/ListenController';
import Heading from '@/components/heading';
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

export default function DeleteListeningHistory() {
    const [open, setOpen] = useState(false);
    const [deleted, setDeleted] = useState(false);

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Delete listening history"
                description="Remove all of your imported listening history"
            />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Warning</p>
                    <p className="text-sm">
                        This permanently deletes all your imported listens. Your
                        account will not be deleted.
                    </p>
                </div>
                <Dialog
                    open={open}
                    onOpenChange={(nextOpen) => {
                        setOpen(nextOpen);
                        if (nextOpen) setDeleted(false);
                    }}
                >
                    <DialogTrigger asChild>
                        <Button variant="destructive">
                            Delete listening history
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Delete all listening history?</DialogTitle>
                        <DialogDescription>
                            This cannot be undone. All of your imported listens
                            will be removed, but you can import your files
                            again. Remembered recording choices will be kept for
                            future imports.
                        </DialogDescription>
                        <Form
                            {...destroy.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => {
                                setOpen(false);
                                setDeleted(true);
                            }}
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
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        {processing
                                            ? 'Deleting...'
                                            : 'Delete all listens'}
                                    </Button>
                                </DialogFooter>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
                {deleted && (
                    <p className="text-sm" role="status">
                        Listening history deleted.
                    </p>
                )}
            </div>
        </div>
    );
}
