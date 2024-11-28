import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { cache } from 'react';
import {phpGWLink} from "@/service/util";
import {IBookingUser} from "@/service/types/api.types";


export const getBookingUser = cache(async (): Promise<IBookingUser | null> => {
    try {
        const url = phpGWLink(['bookingfrontend', 'user']);

        const response = await fetch(url, {
            headers: {
                Cookie: cookies().toString(),
            },
            credentials: 'include',
            cache: 'no-store',
        });

        if (!response.ok) {
            return null;
        }

        return response.json();
    } catch (error) {
        console.error('Error fetching user:', error);
        return null;
    }
});

export async function requireAuth() {
    const user = await getBookingUser();

    if (!user || !user.is_logged_in) {
        redirect('/');
    }

    return user;
}

export function withAuth(handler: Function) {
    return async (request: Request) => {
        const user = await getBookingUser();

        if (!user) {
            return new Response('Unauthorized', { status: 401 });
        }

        return handler(request, user);
    };
}