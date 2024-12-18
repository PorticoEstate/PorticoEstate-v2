import { FilteredEventInfo } from "@/service/api/event-info"
import { Input } from "@digdir/designsystemet-react"
import { faMinusCircle, faPlusCircle } from "@fortawesome/free-solid-svg-icons"
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome"
import styles from '../event.module.scss';
import { FC } from "react"

interface ParticipantInputProps {
  updateField: (key: keyof FilteredEventInfo, value: number) => void;
  fieldValue: number;
}

const MaxParticipantInput: FC<ParticipantInputProps> = ({ updateField, fieldValue }: ParticipantInputProps) => {
  const handler = (action: 'plus' | 'minus') => {
    if (action === 'minus' && fieldValue <= 1) return;
    return action === 'plus' ? 
      updateField('info_participant_limit', fieldValue + 1)
      : updateField('info_participant_limit', fieldValue - 1)
  }
  return (
    <div className={styles.participantInput}>
      <svg onClick={() => handler('minus')} width="49" height="48" viewBox="0 0 49 48" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M14.6797 24C14.6797 18.6152 19.0449 14.25 24.4297 14.25C29.8145 14.25 34.1797 18.6152 34.1797 24C34.1797 29.3848 29.8145 33.75 24.4297 33.75C19.0449 33.75 14.6797 29.3848 14.6797 24ZM24.4297 12.75C18.2165 12.75 13.1797 17.7868 13.1797 24C13.1797 30.2132 18.2165 35.25 24.4297 35.25C30.6429 35.25 35.6797 30.2132 35.6797 24C35.6797 17.7868 30.6429 12.75 24.4297 12.75ZM19.1797 23.25C18.7655 23.25 18.4297 23.5858 18.4297 24C18.4297 24.4142 18.7655 24.75 19.1797 24.75H29.6797C30.0939 24.75 30.4297 24.4142 30.4297 24C30.4297 23.5858 30.0939 23.25 29.6797 23.25H19.1797Z" fill="#834B93"/>
      </svg>
      <Input value={fieldValue} />
      <svg onClick={() => handler('plus')} width="49" height="48" viewBox="0 0 49 48" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M14.8047 24C14.8047 18.6843 19.1139 14.375 24.4297 14.375C29.7454 14.375 34.0547 18.6843 34.0547 24C34.0547 29.3157 29.7454 33.625 24.4297 33.625C19.1139 33.625 14.8047 29.3157 14.8047 24ZM24.4297 12.625C18.1474 12.625 13.0547 17.7178 13.0547 24C13.0547 30.2822 18.1474 35.375 24.4297 35.375C30.7119 35.375 35.8047 30.2822 35.8047 24C35.8047 17.7178 30.7119 12.625 24.4297 12.625ZM24.4297 17.875C24.9129 17.875 25.3047 18.2668 25.3047 18.75V23.125H29.6797C30.1629 23.125 30.5547 23.5168 30.5547 24C30.5547 24.4832 30.1629 24.875 29.6797 24.875H25.3047V29.25C25.3047 29.7332 24.9129 30.125 24.4297 30.125C23.9464 30.125 23.5547 29.7332 23.5547 29.25V24.875H19.1797C18.6964 24.875 18.3047 24.4832 18.3047 24C18.3047 23.5168 18.6964 23.125 19.1797 23.125H23.5547V18.75C23.5547 18.2668 23.9464 17.875 24.4297 17.875Z" fill="#834B93"/>
      </svg>
    </div>
  )
}

export default MaxParticipantInput