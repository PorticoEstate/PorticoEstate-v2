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
                        Link Card
                    </Heading>
                    <Paragraph>
                        Most provide as with carried business are much better more the perfected designer.
                    </Paragraph>
                </a>
            </Card>
        </section>
    );
}

export default UserPageClient


