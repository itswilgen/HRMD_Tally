import { cn } from '@/lib/utils';
import { useEffect, useRef } from 'react';

interface NetworkNode {
    x: number;
    y: number;
    vx: number;
    vy: number;
    radius: number;
}

/** Points closer than this are joined by a line that fades with distance. */
const LINK_DISTANCE = 150;
/** Pixels per frame at most: a slow drift, never a swarm. */
const SPEED = 0.22;
/** One point per this many square pixels, so a phone gets fewer than a monitor. */
const AREA_PER_NODE = 16000;
const MIN_NODES = 24;
const MAX_NODES = 90;

/**
 * The moving network from the HRMD banner, drawn behind the sign-in screen.
 *
 * A single canvas, so it costs one layer whatever its size. It is drawn in the
 * `--landing-line` colour, re-read when the theme changes, and it is careful
 * about the people looking at it:
 *
 * - "Reduce motion" gets the same network, drawn once and left still.
 * - A hidden tab stops the loop entirely instead of animating for nobody.
 * - It is decorative, so it is hidden from screen readers and ignores the
 *   pointer; nothing on the form can ever be covered by it.
 */
export function NetworkBackground({ className }: { className?: string }) {
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (!canvas || !context) {
            return;
        }

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        let width = 0;
        let height = 0;
        let frame = 0;
        let nodes: NetworkNode[] = [];
        let lineColour = '#ffffff';

        const readColour = () => {
            lineColour = getComputedStyle(canvas).getPropertyValue('--landing-line').trim() || '#ffffff';
        };

        const seed = () => {
            const count = Math.min(MAX_NODES, Math.max(MIN_NODES, Math.round((width * height) / AREA_PER_NODE)));

            nodes = Array.from({ length: count }, () => ({
                x: Math.random() * width,
                y: Math.random() * height,
                vx: (Math.random() - 0.5) * 2 * SPEED,
                vy: (Math.random() - 0.5) * 2 * SPEED,
                radius: 1.1 + Math.random() * 1.6,
            }));
        };

        const draw = () => {
            context.clearRect(0, 0, width, height);
            context.strokeStyle = lineColour;
            context.fillStyle = lineColour;
            context.lineWidth = 1;

            for (let i = 0; i < nodes.length; i++) {
                for (let j = i + 1; j < nodes.length; j++) {
                    const dx = nodes[i].x - nodes[j].x;
                    const dy = nodes[i].y - nodes[j].y;
                    const distance = Math.hypot(dx, dy);

                    if (distance < LINK_DISTANCE) {
                        context.globalAlpha = (1 - distance / LINK_DISTANCE) * 0.75;
                        context.beginPath();
                        context.moveTo(nodes[i].x, nodes[i].y);
                        context.lineTo(nodes[j].x, nodes[j].y);
                        context.stroke();
                    }
                }
            }

            context.globalAlpha = 0.95;

            for (const node of nodes) {
                context.beginPath();
                context.arc(node.x, node.y, node.radius, 0, Math.PI * 2);
                context.fill();
            }

            context.globalAlpha = 1;
        };

        const step = () => {
            for (const node of nodes) {
                node.x += node.vx;
                node.y += node.vy;

                // Bounce off the edges rather than wrapping, so no line ever
                // snaps across the whole screen.
                if (node.x < 0 || node.x > width) {
                    node.vx *= -1;
                    node.x = Math.min(Math.max(node.x, 0), width);
                }

                if (node.y < 0 || node.y > height) {
                    node.vy *= -1;
                    node.y = Math.min(Math.max(node.y, 0), height);
                }
            }

            draw();
            frame = window.requestAnimationFrame(step);
        };

        const start = () => {
            window.cancelAnimationFrame(frame);

            if (reducedMotion.matches || document.hidden) {
                draw();

                return;
            }

            frame = window.requestAnimationFrame(step);
        };

        const resize = () => {
            const rect = canvas.getBoundingClientRect();
            const ratio = Math.min(window.devicePixelRatio || 1, 2);

            width = rect.width;
            height = rect.height;
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round(height * ratio);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);

            seed();
            draw();
        };

        readColour();
        resize();
        start();

        const resizeObserver = new ResizeObserver(resize);
        resizeObserver.observe(canvas);

        // The appearance toggle swaps the `dark` class on <html>.
        const themeObserver = new MutationObserver(() => {
            readColour();
            draw();
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        document.addEventListener('visibilitychange', start);
        reducedMotion.addEventListener('change', start);

        return () => {
            window.cancelAnimationFrame(frame);
            resizeObserver.disconnect();
            themeObserver.disconnect();
            document.removeEventListener('visibilitychange', start);
            reducedMotion.removeEventListener('change', start);
        };
    }, []);

    return <canvas ref={canvasRef} aria-hidden="true" className={cn('pointer-events-none absolute inset-0 size-full', className)} />;
}
