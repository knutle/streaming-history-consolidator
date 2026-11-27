import { ArrowLeft } from 'lucide-react';
import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';

type Props = {
    back: ComponentProps<typeof Link>['href'];
    backLabel: string;
    eyebrow: string;
    title: string;
    media: ReactNode;
    children?: ReactNode;
};

export default function DetailHeader({
    back,
    backLabel,
    eyebrow,
    title,
    media,
    children,
}: Props) {
    return (
        <div className="flex flex-col gap-5">
            <Link
                href={back}
                className="flex w-fit items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft aria-hidden="true" className="size-4" />
                {backLabel}
            </Link>
            <header className="flex items-start gap-4 rounded-3xl border bg-emerald-50 p-5 sm:gap-6 sm:p-6 dark:bg-emerald-950/30">
                {media}
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-semibold tracking-wider text-emerald-700 uppercase dark:text-emerald-400">
                        {eyebrow}
                    </p>
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight [overflow-wrap:anywhere] sm:text-3xl">
                        {title}
                    </h1>
                    <div className="mt-3 flex flex-col gap-3 text-sm [overflow-wrap:anywhere]">
                        {children}
                    </div>
                </div>
            </header>
        </div>
    );
}
