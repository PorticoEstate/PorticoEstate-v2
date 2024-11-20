import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import {phpGWLink} from "@/service/util";

export async function getServerSession() {
    const cookieStore = cookies();
    const sessionCookie = cookieStore.get('bookingfrontendsession');


    console.log('Session cookie:', sessionCookie);

    if (!sessionCookie?.value) {
        return null;
    }

    try {
        const response = await fetch(phpGWLink(['bookingfrontend', 'user']), {
            headers: {
                'Cookie': `bookingfrontendsession=${sessionCookie.value}`,
                'Accept': 'application/json'
            },
            credentials: 'include',
        });

        if (!response.ok) {
            return null;
        }

        return await response.json();
    } catch (error) {
        console.error('Session verification failed:', error);
        return null;
    }
}

// Higher order function to protect routes
export function withAuth(Component: React.ComponentType) {
    return async function AuthenticatedComponent() {
        const session = await getServerSession();

        if (!session?.is_logged_in) {
            console.log('Not logged in, redirecting to login page');
            redirect('/');
        }

        return <Component />;
    };
}

// Custom hook for checking authentication in server components
export async function requireAuth() {
    const session = await getServerSession();

    if (!session?.is_logged_in) {
        console.log('Not logged in, redirecting to login page');
        redirect('/');
    }

    return session;
}