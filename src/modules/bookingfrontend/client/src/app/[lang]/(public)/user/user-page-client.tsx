import {FC} from 'react';
import {Card, Heading, Paragraph} from "@digdir/designsystemet-react";

interface UserPageClientProps {
}

const UserPageClient: FC<UserPageClientProps> = (props) => {
    return (
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
                        size="sm"
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
                        size="sm"
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
                        size="sm"
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
                        size="sm"
                    >
                        Delegater
                    </Heading>
                    <Paragraph>
                        Oversikt over dine delegater.
                    </Paragraph>
                </a>
            </Card>
        </section>
    );
}

export default UserPageClient


