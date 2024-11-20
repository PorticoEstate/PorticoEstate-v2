'use client'
import {FC, Fragment, useCallback, useMemo, useState} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import PageHeader from "@/components/page-header/page-header";
import {IBookingUser} from "@/service/types/api.types";
import {useAuthenticatedUser, useBookingUser, useUpdateBookingUser} from "@/service/hooks/api-hooks";
import {faPen} from "@fortawesome/free-solid-svg-icons";
import UserDetailsForm from "@/components/user/details-form/user-details-form";
import {patchBookingUser} from "@/service/api/api-utils";
import {useQueryClient} from "@tanstack/react-query";

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
    const {data: user} = useAuthenticatedUser();
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


