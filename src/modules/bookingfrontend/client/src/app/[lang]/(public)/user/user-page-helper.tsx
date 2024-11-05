import {InformationSquareIcon, PersonGroupIcon, ReceiptIcon, TasklistSendIcon} from "@navikt/aksel-icons";

export const userSubPages: {
    icon: typeof TasklistSendIcon;
    labelTag: string;
    relativePath: string;
    needsDelegates?: boolean;
}[] = [
    {relativePath: '/details', labelTag: 'common.user data', icon: InformationSquareIcon},
    {relativePath: '/applications', labelTag: 'bookingfrontend.applications', icon: TasklistSendIcon},
    {relativePath: '/invoices', labelTag: 'bookingfrontend.invoice', icon: ReceiptIcon},
    {relativePath: '/delegates', labelTag: 'bookingfrontend.delegate from', icon: PersonGroupIcon, needsDelegates: true}
];