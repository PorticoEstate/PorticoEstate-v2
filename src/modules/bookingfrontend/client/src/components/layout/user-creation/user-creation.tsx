'use client'
import React, {FC, useEffect, useState} from 'react';
import UserCreationModal from "@/components/user/creation-modal/user-creation-modal";
import {phpGWLink} from "@/service/util";
import {useBookingUser} from "@/service/hooks/api-hooks";

interface UserCreationProps {
}

const UserCreation: FC<UserCreationProps> = (props) => {
	const [showCreationModal, setShowCreationModal] = useState(false);

	const bookingUserQ = useBookingUser();
	const {data: bookingUser, isLoading, refetch} = bookingUserQ;
	// Show creation modal for first-time users
	useEffect(() => {
		if (!isLoading && bookingUser?.is_logged_in && bookingUser?.needs_profile_creation && !showCreationModal) {
			// setTimeout(() =>
				setShowCreationModal(true)
				// , 800);
		} else if (bookingUser?.is_logged_in && !bookingUser?.needs_profile_creation && showCreationModal) {
			setShowCreationModal(false);
		}
	}, [bookingUser, isLoading, showCreationModal]);

	const handleUserCreated = async () => {
		// Refetch user data to get updated information
		await refetch();
		// setShowCreationModal(false);
	};

	const handleCloseModal = () => {
		// For first-time users, redirect to logout instead of just closing
		if (bookingUser?.needs_profile_creation) {
			window.location.href = phpGWLink(['bookingfrontend', 'logout/']);
		} else {
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


