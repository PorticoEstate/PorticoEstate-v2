import React, {FC, useEffect, useRef} from 'react';

declare global {
	namespace JSX {
		interface IntrinsicElements {
			'vipps-checkout-button': React.DetailedHTMLProps<React.HTMLAttributes<HTMLElement>, HTMLElement> & {
				disabled?: boolean;
				onClick?: (event: Event) => void;
				onclick?: (event: Event) => void;
				type?: 'button' | 'submit';
				brand?: 'vipps' | 'mobilepay';
				language?: 'no' | 'en' | 'da' | 'fi';
				variant?: 'primary' | 'secondary' | 'outline' | 'ghost';
				rounded?: 'true' | 'false';
				verb?: 'continue' | 'pay' | 'buy' | 'order' | 'book' | 'donate' | 'subscribe';
				stretched?: 'true' | 'false';
				branded?: 'true' | 'false';
				loading?: 'true' | 'false';
				'vmp-continue-as-first-name'?: string;
			};
		}
	}
}

interface VippsCheckoutButtonProps {
	language?: 'no' | 'en' | 'da' | 'fi';
	branded?: boolean;
	type?: 'button' | 'submit';
	disabled?: boolean;
	brand?: 'vipps' | 'mobilepay';
	verb?: 'continue' | 'pay' | 'buy' | 'order' | 'book' | 'donate' | 'subscribe';
	vmpContinueAsFirstName?: string;
	variant?: 'primary' | 'secondary' | 'outline' | 'ghost';
	loading?: boolean;
	rounded?: boolean;
	stretched?: boolean;
	onClick?: () => void;
}

const VippsCheckoutButton: FC<VippsCheckoutButtonProps> = ({
															   language = 'no',
															   branded = false,
															   type = 'button',
															   disabled = false,
															   brand = 'vipps',
															   verb = 'buy',
															   vmpContinueAsFirstName = '',
															   variant = 'primary',
															   loading = false,
															   rounded = false,
															   stretched = false,
															   onClick,
														   }) => {
	const buttonRef = useRef<HTMLElement>(null);

	useEffect(() => {
		// The web component is loaded via script tag in the layout
		// This effect is just to ensure it's available before we try to use it
		const checkWebComponent = () => {
			if (typeof window !== 'undefined' && !customElements.get('vipps-checkout-button')) {
				console.warn('Vipps checkout button web component not yet loaded');
			}
		};

		checkWebComponent();
	}, []);

	useEffect(() => {
		const button = buttonRef.current;
		if (button && onClick && button.shadowRoot && button.shadowRoot.children.length > 0) {
			const shButton = button.shadowRoot.children[0];

			const handleClick = () => onClick();
			shButton.addEventListener('click', handleClick);

			return () => shButton.removeEventListener('click', handleClick);
		}
	}, [onClick]);

	return React.createElement('vipps-checkout-button', {
		ref: buttonRef,
		language: language,
		branded: branded ? 'true' : 'false',
		type: type,
		disabled: disabled,
		brand: brand,
		verb: verb,
		'vmp-continue-as-first-name': vmpContinueAsFirstName,
		variant: variant,
		loading: loading ? 'true' : 'false',
		rounded: rounded ? 'true' : 'false',
		stretched: stretched ? 'true' : 'false'
	});
};

export default VippsCheckoutButton;