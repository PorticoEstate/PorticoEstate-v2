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
      <FontAwesomeIcon 
        onClick={() => handler('minus')}
        icon={faMinusCircle}
        size="1x"
      />
      <Input value={fieldValue} />
      <FontAwesomeIcon 
        onClick={() => handler('plus')}
        icon={faPlusCircle} 
        size="1x"
      />
    </div>
  )
}

export default MaxParticipantInput