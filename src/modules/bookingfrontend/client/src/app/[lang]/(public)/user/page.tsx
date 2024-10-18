import {getTranslation} from "@/app/i18n";
import PageHeader from "@/components/page-header/page-header";

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
        <main>
            <PageHeader title={t('bookingfrontend.my page')} />

        </main>
    );
}

export default UserPage


