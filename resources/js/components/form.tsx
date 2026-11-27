import { Form as InertiaForm, usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';

export default function Form<
    TForm extends object = Record<string, FormDataEntryValue>,
>({ options, ...props }: ComponentProps<typeof InertiaForm<TForm>>) {
    const currentPage = usePage();

    return (
        <InertiaForm<TForm>
            {...props}
            options={{
                preserveScroll: (page) =>
                    page.component === currentPage.component &&
                    new URL(page.url, 'http://localhost').pathname ===
                        new URL(currentPage.url, 'http://localhost').pathname,
                ...options,
            }}
        />
    );
}
