import {getTranslation} from "@/app/i18n";
import React from "react";
import UserPageClient from "@/app/[lang]/(public)/user/(user-main)/user-page-client";

interface UserPageProps {
}


export async function generateMetadata(props: UserPageProps) {
    const {t} = await getTranslation();
    return {
        title: t('bookingfrontend.my page'),
    }
}

const UserPage = async (props: UserPageProps) => {
    const {t} = await getTranslation();

    return (
        // <main>
        //     <PageHeader title={t('bookingfrontend.my page')} />
            <UserPageClient />
    );
}

export default UserPage


