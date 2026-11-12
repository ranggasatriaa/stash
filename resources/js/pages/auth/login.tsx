import { Form, Head } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { store } from '@/routes/login';

type Props = {
    status?: string;
};

export default function Login({ status }: Props) {
    return (
        <>
            <Head title="Log in" />

            {status && (
                <div className="st-alert st-alert--info mb-4" role="status">
                    <div className="st-alert__content">
                        <p className="st-alert__body">{status}</p>
                    </div>
                </div>
            )}

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        {(errors.email || errors.password) && (
                            <div
                                className="st-alert st-alert--error"
                                role="alert"
                            >
                                <CircleAlert
                                    className="st-alert__icon"
                                    aria-hidden="true"
                                />
                                <div className="st-alert__content">
                                    <p className="st-alert__body">
                                        {errors.email ?? errors.password}
                                    </p>
                                </div>
                            </div>
                        )}

                        <div className="st-field">
                            <label htmlFor="email" className="st-field__label">
                                Email address
                            </label>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                className="st-input"
                                required
                                autoFocus
                                autoComplete="username"
                                placeholder="you@example.com"
                                aria-invalid={errors.email ? true : undefined}
                            />
                        </div>

                        <div className="st-field">
                            <label
                                htmlFor="password"
                                className="st-field__label"
                            >
                                Password
                            </label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="current-password"
                                placeholder="Password"
                            />
                        </div>

                        <label className="st-check">
                            <input
                                type="checkbox"
                                id="remember"
                                name="remember"
                                value="1"
                                className="st-checkbox"
                            />
                            Keep me signed in on this device
                        </label>

                        <button
                            type="submit"
                            className="st-btn st-btn--primary st-btn--lg st-btn--block"
                            aria-busy={processing || undefined}
                            disabled={processing}
                            data-test="login-button"
                        >
                            Log in
                        </button>

                        <p className="st-meta text-center">
                            Private library — accounts are created by the owner
                            with{' '}
                            <code className="st-mono">
                                php artisan stash:create-owner
                            </code>
                            .
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Log in',
    description: 'Welcome back to your stash.',
};
