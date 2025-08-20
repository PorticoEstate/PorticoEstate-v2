'use client'
import {FC, useMemo} from 'react';
import {Card, Heading, Paragraph} from "@digdir/designsystemet-react";
import PageHeader from "@/components/page-header/page-header";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {userSubPages} from "@/app/[lang]/(public)/user/(user-main)/user-page-helper";
import {useBookingUser} from "@/service/hooks/api-hooks";
import Link from "next/link";

interface UserPageClientProps {
}

const UserPageClient: FC<UserPageClientProps> = (props) => {
    const t = useTrans();
    const user = useBookingUser();

    const links = useMemo(() => {
        return userSubPages.filter(a => !a.needsDelegates || (user.data?.delegates?.length || 0) > 0);
    }, [user]);

    return (
        <main>
            <PageHeader title={t('bookingfrontend.my page')}/>

            <section style={{display: 'flex', flexDirection: 'column', gap: '0.5rem'}}>
                {links.map((link, index) => {
                    const SVGIcon = link.icon;
                    const fullPath = '/user' + link.relativePath;

                    return (
                        <Card
                            key={index}
                            asChild
                            color="neutral"
                        >
                            <Link href={fullPath} className={'no-decoration'}>
                                <Heading
                                    level={2}
                                    data-size="sm"
									className={'text-primary'}

                                >
                                    <span style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                                        <SVGIcon fontSize='1.75rem' aria-hidden />
                                        {t(link.labelTag)}
                                    </span>
                                </Heading>
                                <Paragraph>
                                    {t(link.labelTag + '.description') || t('bookingfrontend.description not available')}
                                </Paragraph>
                            </Link>
                        </Card>
                    );
                })}
            </section>
        </main>
    );
}

export default UserPageClient