import { router, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

/** How often an open, visible page fetches fresh props (#4723). */
export const AUTO_REFRESH_MS = 60_000;

/**
 * The props this refresh asks for (#5731): a page that sends `refreshOnly` (its live props
 * plus the bell and the signed-in user, `App\Support\AutoRefresh`) gets a partial
 * reload of just those, so the server skips the rest. Without it, every prop reloads.
 */
export function refreshProps(props: Record<string, unknown>): string[] | null {
    const only = props.refreshOnly;

    return Array.isArray(only) &&
        only.length > 0 &&
        only.every((p) => typeof p === 'string')
        ? (only as string[])
        : null;
}

/** A field the visitor may be typing in: never refresh under their fingers. */
function isEditing(): boolean {
    const el = document.activeElement;

    if (!(el instanceof HTMLElement)) {
        return false;
    }

    return (
        el.isContentEditable ||
        el instanceof HTMLTextAreaElement ||
        el instanceof HTMLSelectElement ||
        (el instanceof HTMLInputElement &&
            !['button', 'checkbox', 'radio', 'submit', 'reset'].includes(
                el.type,
            ))
    );
}

/**
 * Reloads the current page's props (a partial Inertia reload: no flash, scroll and
 * form state kept) once a minute while the tab is visible. A hidden tab doesn't
 * poll; coming back after more than a minute refreshes at once. Any navigation or
 * reload resets the clock, and nothing fires while another visit is running or
 * the visitor is typing.
 */
export function useAutoRefresh(intervalMs = AUTO_REFRESH_MS): void {
    const page = usePage();
    let lastLoadedAt = Date.now();
    let timer: number | null = null;
    let busy = 0;
    const off: Array<() => void> = [];

    const schedule = (delay: number) => {
        if (timer !== null) {
            window.clearTimeout(timer);
        }

        timer = window.setTimeout(tick, Math.max(0, delay));
    };

    const tick = () => {
        timer = null;

        if (document.visibilityState !== 'visible') {
            return; // the visibilitychange listener picks it up again
        }

        const due = intervalMs - (Date.now() - lastLoadedAt);

        if (due > 0) {
            schedule(due);

            return;
        }

        if (busy > 0 || isEditing()) {
            schedule(intervalMs);

            return;
        }

        const only = refreshProps(page.props);
        router.reload({
            async: true,
            showProgress: false,
            ...(only ? { only } : {}),
        });
        schedule(intervalMs);
    };

    const onVisibility = () => {
        if (document.visibilityState === 'visible') {
            schedule(intervalMs - (Date.now() - lastLoadedAt));
        } else if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    };

    onMounted(() => {
        off.push(
            router.on('start', () => {
                busy++;
            }),
            router.on('finish', () => {
                busy = Math.max(0, busy - 1);
            }),
            router.on('navigate', () => {
                // A page arrived, so no visit is still pending; also heals the
                // count if a request ever failed before its finish event.
                busy = 0;
                lastLoadedAt = Date.now();
                schedule(intervalMs);
            }),
        );
        document.addEventListener('visibilitychange', onVisibility);
        schedule(intervalMs);
    });

    onUnmounted(() => {
        off.forEach((stop) => stop());
        document.removeEventListener('visibilitychange', onVisibility);

        if (timer !== null) {
            window.clearTimeout(timer);
        }
    });
}
