import ApplicationDetails from "@/components/user/application/application-details";
import {fetchApplication} from "@/service/api/api-utils";
interface ApplicationDetailProps {
  params: {
    id: string;
  }
}



const ApplicationDetailPage = async ({params}: ApplicationDetailProps) => {
  const applicationId = parseInt(params.id, 10);
  const initialApplication = await fetchApplication(applicationId);
  return <ApplicationDetails applicationId={applicationId} initialApplication={initialApplication} />
};

export default ApplicationDetailPage;