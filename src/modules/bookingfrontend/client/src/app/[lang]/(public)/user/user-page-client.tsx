'use client'
import {FC} from 'react';
import {Card, Heading, Paragraph} from "@digdir/designsystemet-react";
import PageHeader from "@/components/page-header/page-header";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";

interface UserPageClientProps {
}

const UserPageClient: FC<UserPageClientProps> = (props) => {
    const t = useTrans();

    return (
        <main>
            <PageHeader title={t('bookingfrontend.my page')}/>

            <section>
                <Card
                    asChild
                    color="neutral"
                >
                    <a
                        href="https://designsystemet.no"
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        <Heading
                            level={2}
                            data-size="sm"
                        >
                            Brukerdata
                        </Heading>
                        <Paragraph>
                            Din personlig informasjon og faktura informasjon.
                        </Paragraph>
                    </a>
                </Card>
                <Card
                    asChild
                    color="neutral"
                >
                    <a
                        href="https://designsystemet.no"
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        <Heading
                            level={2}
                            data-size="sm"
                        >
                            Søknader
                        </Heading>
                        <Paragraph>
                            Oversikt over dine søknader.
                        </Paragraph>
                    </a>
                </Card>
                <Card
                    asChild
                    color="neutral"
                >
                    <a
                        href="https://designsystemet.no"
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        <Heading
                            level={2}
                            data-size="sm"
                        >
                            Faktura
                        </Heading>
                        <Paragraph>
                            Oversikt over dine fakturaer.
                        </Paragraph>
                    </a>
                </Card>
                <Card
                    asChild
                    color="neutral"
                >
                    <a
                        href="https://designsystemet.no"
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        <Heading
                            level={2}
                            data-size="sm"
                        >
                            Delegater
                        </Heading>
                        <Paragraph>
                            Oversikt over dine delegater.
                        </Paragraph>
                    </a>
                </Card>
            </section>
        </main>
    );
}

export default UserPageClient


