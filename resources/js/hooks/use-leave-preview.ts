import { JsonRequestError, postJson } from '@/lib/json-request';
import { type DayPartValue, type LeavePreview } from '@/types';
import { useEffect, useState } from 'react';

interface LeavePreviewInput {
    /** The form's value, so '' means "nobody chosen yet". */
    employeeId: string;
    dateFrom: string;
    dateTo: string;
    dayPart: DayPartValue;
    /** The record being edited, which must not conflict with itself. */
    ignoreRecordId?: number | null;
    /** False while the panel is closed: a closed form asks nothing. */
    enabled: boolean;
}

export interface LeavePreviewState {
    result: LeavePreview | null;
    loading: boolean;
    /** The answer could not be fetched; the form still submits and validates. */
    failed: boolean;
}

/** Y-m-d, which is what the Form Request accepts and nothing else. */
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

const DEBOUNCE_MS = 300;

/**
 * Asks the server what saving this range would do, while the encoder types.
 *
 * Debounced, and every answer but the newest is thrown away: typing a date
 * fires four keystrokes' worth of ranges, and the one that matters is the last.
 * A rejected range (422) is simply "no preview yet" — the wording of what is
 * wrong belongs to the real submit, next to the field it concerns.
 */
export function useLeavePreview({ employeeId, dateFrom, dateTo, dayPart, ignoreRecordId = null, enabled }: LeavePreviewInput): LeavePreviewState {
    const [state, setState] = useState<LeavePreviewState>({ result: null, loading: false, failed: false });

    const ready = enabled && employeeId !== '' && ISO_DATE.test(dateFrom) && ISO_DATE.test(dateTo) && dateTo >= dateFrom;

    useEffect(() => {
        if (!ready) {
            setState({ result: null, loading: false, failed: false });

            return;
        }

        const controller = new AbortController();

        setState((current) => ({ ...current, loading: true, failed: false }));

        const timer = window.setTimeout(() => {
            postJson<LeavePreview>(
                route('leave.preview'),
                {
                    employee_id: Number(employeeId),
                    date_from: dateFrom,
                    date_to: dateTo,
                    day_part: dayPart,
                    ignore_record_id: ignoreRecordId,
                },
                controller.signal,
            )
                .then((result) => setState({ result, loading: false, failed: false }))
                .catch((error: unknown) => {
                    if (controller.signal.aborted) {
                        return;
                    }

                    // 422 means the range is not answerable yet, not that
                    // anything broke: stay quiet and let the submit say why.
                    const unanswerable = error instanceof JsonRequestError && error.status === 422;

                    setState({ result: null, loading: false, failed: !unanswerable });
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [ready, employeeId, dateFrom, dateTo, dayPart, ignoreRecordId]);

    return state;
}
