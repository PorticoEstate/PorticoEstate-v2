'use client'

import { IDocument } from '@/service/types/api.types'
import { getDocumentLink } from '@/service/api/building'
import styles from './avatar.module.scss'

interface AvatarProps {
  document: IDocument
  name: string
  type: 'building' | 'resource' | 'application' | 'organization'
  size?: 'small' | 'medium' | 'large'
}

const Avatar = ({ document, name, type, size = 'medium' }: AvatarProps) => {
  return (
    <img 
      src={getDocumentLink(document, type)} 
      alt={`${name} avatar`}
      className={`${styles.avatar} ${styles[size]}`}
    />
  )
}

export default Avatar