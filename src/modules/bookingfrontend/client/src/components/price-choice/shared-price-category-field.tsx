// components/price-choice/shared-price-category-field.tsx
import React, {forwardRef} from 'react';
import {Field, Label, Select, ValidationMessage} from '@digdir/designsystemet-react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {SharedCategory} from '@/utils/price-category';

interface SharedPriceCategoryFieldProps {
	/** The categories found on every chosen resource */
	categories: SharedCategory[];
	/** The category every resource is priced in; null while they are not all in one */
	value: number | null;
	onChange: (categoryId: number) => void;
	/** Shown at the field when a resource still has no price */
	error?: string;
	disabled?: boolean;
}

/**
 * The applicant's price choice ("Hvem søker du som?") made once for all the
 * chosen resources (#1477), in place of one choice per resource. Each resource
 * is then priced at its own row in the category; the article table below shows
 * each resource's line and the total.
 */
const SharedPriceCategoryField = forwardRef<HTMLSelectElement, SharedPriceCategoryFieldProps>(({
	categories,
	value,
	onChange,
	error,
	disabled
}, ref) => {
	const t = useTrans();
	const chosen = categories.find(category => category.id === value);

	return (
		<Field>
			<Label>
				{t('bookingfrontend.price_choice_label')} <span className="required-asterisk">*</span>
			</Label>
			<Field.Description>
				{t('bookingfrontend.price_choice_help')} {t('bookingfrontend.price_category_shared_help')}
			</Field.Description>
			<Select
				ref={ref}
				value={chosen ? String(chosen.id) : ''}
				onChange={(event) => onChange(Number(event.target.value))}
				aria-invalid={!!error}
				aria-required={true}
				disabled={disabled}
			>
				{!chosen && (
					<Select.Option value="" disabled>
						{t('bookingfrontend.price_choice_placeholder')}
					</Select.Option>
				)}
				{categories.map(category => (
					<Select.Option key={category.id} value={String(category.id)}>
						{category.name}
					</Select.Option>
				))}
			</Select>
			{error && <ValidationMessage>{error}</ValidationMessage>}
		</Field>
	);
});

SharedPriceCategoryField.displayName = 'SharedPriceCategoryField';

export default SharedPriceCategoryField;
