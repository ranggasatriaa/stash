import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    timezones: string[];
    preferences: {
        timezone: string;
        default_sort: string;
        page_size: number;
        layout: string;
    };
};

export default function Preferences({ timezones, preferences }: Props) {
    const form = useForm(preferences);
    const browserTz =
        typeof Intl !== 'undefined'
            ? Intl.DateTimeFormat().resolvedOptions().timeZone
            : null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch('/settings/preferences', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Preferences" />
            <form
                onSubmit={submit}
                className="st-panel flex flex-col gap-5 p-5 sm:p-6"
            >
                <div>
                    <h2 className="st-h3">Preferences</h2>
                    <p className="mt-1 text-sm text-muted-text">
                        Dates are stored in UTC and shown in your display
                        timezone. Date filters use this timezone too.
                    </p>
                </div>

                <div className="st-field">
                    <label htmlFor="timezone" className="st-field__label">
                        Display timezone
                    </label>
                    <select
                        id="timezone"
                        className="st-select"
                        value={form.data.timezone}
                        onChange={(e) =>
                            form.setData('timezone', e.target.value)
                        }
                    >
                        {timezones.map((tz) => (
                            <option key={tz} value={tz}>
                                {tz}
                            </option>
                        ))}
                    </select>
                    {browserTz && browserTz !== form.data.timezone && (
                        <button
                            type="button"
                            className="st-link w-fit text-sm"
                            onClick={() => form.setData('timezone', browserTz)}
                        >
                            Use this device’s timezone ({browserTz})
                        </button>
                    )}
                    {form.errors.timezone && (
                        <p className="st-field__error">
                            {form.errors.timezone}
                        </p>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="st-field">
                        <label
                            htmlFor="default_sort"
                            className="st-field__label"
                        >
                            Default sort
                        </label>
                        <select
                            id="default_sort"
                            className="st-select"
                            value={form.data.default_sort}
                            onChange={(e) =>
                                form.setData('default_sort', e.target.value)
                            }
                        >
                            <option value="newest">Newest</option>
                            <option value="oldest">Oldest</option>
                            <option value="title">Title A → Z</option>
                        </select>
                    </div>
                    <div className="st-field">
                        <label htmlFor="page_size" className="st-field__label">
                            Items per page
                        </label>
                        <select
                            id="page_size"
                            className="st-select"
                            value={form.data.page_size}
                            onChange={(e) =>
                                form.setData(
                                    'page_size',
                                    Number(e.target.value),
                                )
                            }
                        >
                            {[24, 48, 96].map((n) => (
                                <option key={n} value={n}>
                                    {n}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="st-field">
                        <label htmlFor="layout" className="st-field__label">
                            Library layout
                        </label>
                        <select
                            id="layout"
                            className="st-select"
                            value={form.data.layout}
                            onChange={(e) =>
                                form.setData('layout', e.target.value)
                            }
                        >
                            <option value="grid">Cards</option>
                            <option value="table">List</option>
                        </select>
                    </div>
                </div>

                <div>
                    <button
                        type="submit"
                        className="st-btn st-btn--primary"
                        disabled={form.processing}
                        aria-busy={form.processing || undefined}
                    >
                        Save preferences
                    </button>
                </div>
            </form>
        </>
    );
}
