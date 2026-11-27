import { AudioLines } from 'lucide-react';
import type { ComponentProps } from 'react';

export default function AppLogoIcon(props: ComponentProps<typeof AudioLines>) {
    return <AudioLines {...props} aria-hidden="true" />;
}
