'use client'
import React, {FC, useEffect, useState} from 'react';
import UserCreationModal from "@/components/user/creation-modal/user-creation-modal";
import {phpGWLink} from "@/service/util";
import {useBookingUser} from "@/service/hooks/api-hooks";

const DEBUG_USER_CREATION = false;

interface UserCreationProps {
}

const UserCreation: FC<UserCreationProps> = (props) => {
	const [showCreationModal, setShowCreationModal] = useState(false);

	const bookingUserQ = useBookingUser();
	const {data: bookingUser, isLoading, refetch} = bookingUserQ;
	// Show creation modal for first-time users
	useEffect(() => {
		if (DEBUG_USER_CREATION) console.log('UserCreation useEffect triggered:', {
			isLoading,
			bookingUser: bookingUser ? {
				is_logged_in: bookingUser.is_logged_in,
				needs_profile_creation: bookingUser.needs_profile_creation,
				name: bookingUser.name
			} : null,
			showCreationModal
		});

		if (!isLoading && bookingUser?.is_logged_in && bookingUser?.needs_profile_creation && !showCreationModal) {
			if (DEBUG_USER_CREATION) console.log('Setting showCreationModal to TRUE');
			setShowCreationModal(true);
		} else if (bookingUser?.is_logged_in && !bookingUser?.needs_profile_creation && showCreationModal) {
			if (DEBUG_USER_CREATION) console.log('Setting showCreationModal to FALSE - user no longer needs profile creation');
			setShowCreationModal(false);
		}
	}, [bookingUser, isLoading, showCreationModal]);

	const handleUserCreated = async () => {
		if (DEBUG_USER_CREATION) console.log('handleUserCreated called - refetching user data');
		// Refetch user data to get updated information
		await refetch();
		if (DEBUG_USER_CREATION) console.log('User data refetched after creation');
		// setShowCreationModal(false);
	};

	const handleCloseModal = () => {
		if (DEBUG_USER_CREATION) console.log('handleCloseModal called, needs_profile_creation:', bookingUser?.needs_profile_creation);
		// For first-time users, redirect to logout instead of just closing
		if (bookingUser?.needs_profile_creation) {
			if (DEBUG_USER_CREATION) console.log('Redirecting to logout');
			window.location.href = phpGWLink(['bookingfrontend', 'logout/']);
		} else {
			if (DEBUG_USER_CREATION) console.log('Closing modal normally');
			setShowCreationModal(false);
		}
	};

	return (
		<UserCreationModal
			open={showCreationModal}
			onClose={handleCloseModal}
			onUserCreated={handleUserCreated}
		/>
	);
}

export default UserCreation


