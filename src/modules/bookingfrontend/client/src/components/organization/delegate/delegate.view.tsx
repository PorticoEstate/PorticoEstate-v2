'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Delegate } from "@/service/types/api/organization.types";

interface DelegateViewProps {
    data: Delegate;
}

const DelegateView = ({ data }: DelegateViewProps) => {
    const t = useTrans();
    
    //TODO: delegate view/edit design
    return null;
}

export default DelegateView;