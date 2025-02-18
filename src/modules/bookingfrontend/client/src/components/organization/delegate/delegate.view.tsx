'use client'
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Delegate } from "@/service/types/api/organization.types";

interface DelegateViewProps {
    delegate: Delegate;
}

const DelegateView = ({ delegate }: DelegateViewProps) => {
    const t = useTrans();
    
    //TODO: delegate view/edit design
    return null;
}

export default DelegateView;