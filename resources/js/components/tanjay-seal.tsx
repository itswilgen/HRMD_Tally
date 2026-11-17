import { cn } from '@/lib/utils';

interface TanjaySealProps {
    /** Rendered size in CSS pixels; the right file is picked for sharp edges. */
    size: number;
    /**
     * Empty when a visible label beside the seal already names the system, so
     * a screen reader does not read the same thing twice.
     */
    alt?: string;
    className?: string;
}

/**
 * The official seal of the City of Tanjay, the mark of the whole system.
 *
 * Served from public/images/brand at three sizes with a transparent ground, so
 * it sits cleanly on the blue sidebar, the white sheets and the landing
 * gradient alike. Width and height are always set, so nothing jumps while it
 * loads.
 */
export function TanjaySeal({ size, alt = 'Official seal of the City of Tanjay', className }: TanjaySealProps) {
    const source =
        size <= 48 ? '/images/brand/tanjay-seal-96.png' : size <= 96 ? '/images/brand/tanjay-seal-192.png' : '/images/brand/tanjay-seal-256.png';

    return (
        <img src={source} alt={alt} width={size} height={size} decoding="async" className={cn('shrink-0 select-none', className)} draggable={false} />
    );
}
