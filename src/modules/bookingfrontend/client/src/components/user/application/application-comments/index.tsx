'use client'
import React, {FC} from 'react';
import {useApplicationComments} from "@/service/hooks/api-hooks";
import History from "./history";
import Create from "./create";

interface ApplicationCommentsProps {
	applicationId: number;
	secret?: string;
}

const ApplicationComments: FC<ApplicationCommentsProps> = ({ applicationId, secret }) => {
	const { data: commentsData, isLoading: commentsLoading } = useApplicationComments(
		applicationId,
		"comment,ownership,status",
		secret
	);

	return (
		<>
			<History commentsData={commentsData} isLoading={commentsLoading} />
			<Create applicationId={applicationId} secret={secret} />
		</>
	);
};

export default ApplicationComments;