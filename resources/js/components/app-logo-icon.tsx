import { SVGAttributes } from 'react';

/**
 * Four tally marks — the gesture the paper sheet this system replaces is
 * covered in. Drawn with strokes so it stays legible at 16px.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {...props}>
            <g stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <path d="M5 4.5v12" />
                <path d="M10 4.5v12" />
                <path d="M15 4.5v12" />
                <path d="M3 18.5 18 3.5" />
            </g>
        </svg>
    );
}
