import { Form, Head, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';

export default function Profile() {
    const user = usePage().props.auth.user;

    return (
        <>
            <Head title="Profile settings" />
            <div className="st-panel p-5 sm:p-6">
                <h2 className="st-h3 mb-1">Profile</h2>
                <p className="mb-5 text-sm text-muted-text">
                    Your name and the e-mail you sign in with.
                </p>
                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="st-field">
                                <label
                                    htmlFor="name"
                                    className="st-field__label"
                                >
                                    Name
                                </label>
                                <input
                                    id="name"
                                    name="name"
                                    className="st-input"
                                    defaultValue={user.name}
                                    required
                                    autoComplete="name"
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />
                                {errors.name && (
                                    <p className="st-field__error">
                                        {errors.name}
                                    </p>
                                )}
                            </div>
                            <div className="st-field">
                                <label
                                    htmlFor="email"
                                    className="st-field__label"
                                >
                                    Email address
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    className="st-input"
                                    defaultValue={user.email}
                                    required
                                    autoComplete="username"
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                />
                                {errors.email && (
                                    <p className="st-field__error">
                                        {errors.email}
                                    </p>
                                )}
                            </div>
                            <div>
                                <button
                                    type="submit"
                                    className="st-btn st-btn--primary"
                                    disabled={processing}
                                    aria-busy={processing || undefined}
                                    data-test="update-profile-button"
                                >
                                    Save profile
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
