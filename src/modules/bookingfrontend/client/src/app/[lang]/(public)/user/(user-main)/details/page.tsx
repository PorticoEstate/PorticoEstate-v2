'use client'
import {FC} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {IBookingUser} from "@/service/types/api.types";
import {useBookingUser, useUpdateBookingUser} from "@/service/hooks/api-hooks";
import UserDetailsForm from "@/components/user/details-form/user-details-form";

interface DetailsProps {
}

interface UserField {
    label: string,
    key: keyof IBookingUser,
    edit?: boolean
}

interface UserFieldCategory {
    category: string;
    fields: UserField[];
}


const Details: FC<DetailsProps> = (props) => {
    const t = useTrans();
    const {data: user} = useBookingUser();
    const updateUser = useUpdateBookingUser();
    if (!user) {
        return null;
    }
    return (
        <main>
            <UserDetailsForm user={user} onUpdate={async (data) => {
                updateUser.mutate(data)
            }}/>
        </main>
    );
}

export default Details


