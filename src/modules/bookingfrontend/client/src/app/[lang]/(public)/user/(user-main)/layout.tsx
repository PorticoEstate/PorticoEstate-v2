import {PropsWithChildren} from 'react';

import ClientLayout from "../(user-main)/client-layout";
import {getTranslation} from "@/app/i18n";
import {headers} from "next/headers";
import {userSubPages} from "../(user-main)/user-page-helper";
import AuthGuard from "@/components/user/auth-guard";

interface UserLayoutProps extends PropsWithChildren {
}

export const dynamic = 'force-dynamic'

export async function generateMetadata(props: UserLayoutProps) {
    const {t} = await getTranslation();
    const headersList = headers();
    const path = headersList.get('x-current-path')?.split('user');
    if (path && path.length === 2) {
        const currPage = userSubPages.find(a => a.relativePath === path[1]);
        if (currPage) {
            return {
                title: t(currPage?.labelTag),
            }
        }

    }

    return {
        title: t('bookingfrontend.my page'),
    }
}

const UserLayout= async (props: UserLayoutProps) => {
    // await requireAuth();
    return (
        <AuthGuard>
            <ClientLayout>{props.children}</ClientLayout>
        </AuthGuard>
);
}
export default UserLayout

