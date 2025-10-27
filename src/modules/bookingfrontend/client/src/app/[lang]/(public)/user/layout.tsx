'use client'
import React, { FC, PropsWithChildren } from 'react';
import { useBookingUser } from "@/service/hooks/api-hooks";
import { useRouter, useSearchParams } from "next/navigation";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Spinner } from "@digdir/designsystemet-react";

interface UserRootLayoutProps extends PropsWithChildren {}

const UserRootLayout: FC<UserRootLayoutProps> = ({ children }) => {
  const user = useBookingUser();
  const router = useRouter();
  const t = useTrans();
  const searchParams = useSearchParams();
  
  // Check if there's a secret parameter for external access
  const hasSecret = searchParams.get('secret');

  if (!user.data?.is_logged_in && !user.isLoading && !hasSecret) {
    router.push('/');
    return null;
  }

  if (user.isLoading && !hasSecret) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', padding: '2rem' }}>
        <Spinner data-size="lg" aria-label={t('common.loading')} />
      </div>
    );
  }

  return children;
};

export default UserRootLayout;