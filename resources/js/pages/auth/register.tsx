import { login } from '@/routes';
import TextLink from '@/components/text-link';
import AuthLayout from '@/layouts/auth-layout';
import { Head } from '@inertiajs/react';

export default function Register() {
    return (
        <AuthLayout
            title="Registration disabled"
            description="Accounts can only be created by an administrator."
        >
            <Head title="Register" />

            <div className="space-y-4 text-center">
                <p className="text-sm text-muted-foreground">
                    Contact your administrator if you need access to this
                    system.
                </p>

                <TextLink href={login()} tabIndex={1}>
                    Return to log in
                </TextLink>
            </div>
        </AuthLayout>
    );
}
