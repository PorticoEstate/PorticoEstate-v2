import {PropsWithChildren} from 'react';

import ClientLayout from "@/app/[lang]/(public)/user/client-layout";
import {getTranslation} from "@/app/i18n";
import {headers} from "next/headers";
import {userSubPages} from "@/app/[lang]/(public)/user/user-page-helper";

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
        <ClientLayout>{props.children}</ClientLayout>
    );
}
export default UserLayout

