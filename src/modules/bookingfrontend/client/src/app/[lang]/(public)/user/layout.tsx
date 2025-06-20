'use client'
import React, { FC, PropsWithChildren } from 'react';
import { useBookingUser } from "@/service/hooks/api-hooks";
import { useRouter } from "next/navigation";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Spinner } from "@digdir/designsystemet-react";

interface UserRootLayoutProps extends PropsWithChildren {}

const UserRootLayout: FC<UserRootLayoutProps> = ({ children }) => {
  const user = useBookingUser();
  const router = useRouter();
  const t = useTrans();

  if (!user.data?.is_logged_in && !user.isLoading) {
    router.push('/');
    return null;
  }

  if (user.isLoading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', padding: '2rem' }}>
        <Spinner data-size="lg" aria-label={t('common.loading')} />
      </div>
    );
  }

  return children;
};

export default UserRootLayout;