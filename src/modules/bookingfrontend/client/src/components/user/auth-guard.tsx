'use client'
import { useEffect } from 'react';
import { useBookingUser } from "@/service/hooks/api-hooks";
import { useRouter } from 'next/navigation';
import { phpGWLink } from "@/service/util";

interface AuthGuardProps {
    children: React.ReactNode;
}

const AuthGuard = ({ children }: AuthGuardProps) => {
    const router = useRouter();
    const { data: bookingUser, isLoading } = useBookingUser();

    useEffect(() => {
        if (!isLoading && !bookingUser?.is_logged_in) {
            // Redirect to login page with return URL
            const returnUrl = encodeURI(window.location.href.split('bookingfrontend')[1]);
            const loginUrl = phpGWLink(['bookingfrontend', 'login/'], { after: returnUrl });
            router.replace(loginUrl);
        }
    }, [bookingUser, isLoading, router]);

    // Show nothing while checking authentication
    if (isLoading) {
        return null;
    }

    // Show children only if authenticated
    return bookingUser?.is_logged_in ? <>{children}</> : null;
};

export default AuthGuard;