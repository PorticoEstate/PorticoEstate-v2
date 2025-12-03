import ApplicationDetails from "@/components/user/application/application-details";
import {fetchApplication} from "@/service/api/api-utils";
interface ApplicationDetailProps {
  params: {
    id: string;
  }
  searchParams: {
    secret?: string;
  }
}



const ApplicationDetailPage = async ({params, searchParams}: ApplicationDetailProps) => {
  const applicationId = parseInt(params.id, 10);
  const secret = searchParams.secret;
  const initialApplication = await fetchApplication(applicationId, secret);
  return <ApplicationDetails applicationId={applicationId} initialApplication={initialApplication} secret={secret} />
};

export default ApplicationDetailPage;