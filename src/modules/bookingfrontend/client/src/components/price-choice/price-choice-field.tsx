// components/price-choice/price-choice-field.tsx
import React, {forwardRef} from 'react';
import {Field, Label, Paragraph, Select, ValidationMessage} from '@digdir/designsystemet-react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IPriceOption} from '@/service/types/api/order-articles.types';
import {formatCurrency} from '@/utils/cost-utils';
import {
	formatHourlyRate,
	formatTotalWithHourlyRate,
	hourlyRateOfMinuteArticle,
	isMinuteUnit,
	lineTotalOre,
	minuteArticleTotal
} from '@/utils/article-pricing';

interface PriceChoiceFieldProps {
	/** The prices the resource offers today */
	options: IPriceOption[];
	/** The chosen price_id; null or undefined while nothing is chosen */
	value?: number | null;
	onChange: (priceId: number) => void;
	/** Appended to the label when several rooms each need a choice */
	resourceName?: string;
	/** What the price is multiplied by for the price line (hours for an hourly room, minutes for a minute-priced one) */
	quantity?: number;
	/** The room's unit. A 'minute' price is never shown per minute, but as the hourly price */
	unit?: string;
	/** The room's VAT percent. Required: every price line is worked out from the ex-VAT price to the øre, and a missing one would silently drop the VAT */
	taxPercent: number;
	/** Shown at the field when a required choice is missing */
	error?: string;
	disabled?: boolean;
	id?: string;
}

/**
 * The applicant's price choice ("Hvem søker du som?"), shown when a room has
 * more than one price. The price follows the choice immediately; until there
 * is one it reads "Beregnes når du har valgt", never 0.
 */
const PriceChoiceField = forwardRef<HTMLSelectElement, PriceChoiceFieldProps>(({
	options,
	value,
	onChange,
	resourceName,
	quantity = 1,
	unit,
	taxPercent,
	error,
	disabled,
	id
}, ref) => {
	const t = useTrans();
	const chosen = options.find(option => option.price_id === value);

	const isMinute = isMinuteUnit(unit);
	const hourlyRateOf = (option: IPriceOption) =>
		hourlyRateOfMinuteArticle({ex_tax_price: option.ex_tax_price, tax_percent: taxPercent});

	const optionText = (option: IPriceOption) => {
		const amount = isMinute
			? formatHourlyRate(hourlyRateOf(option), t)
			: formatCurrency(parseFloat(option.price));
		return option.remark ? `${option.remark} – ${amount}` : amount;
	};

	const chosenPriceText = (option: IPriceOption) => isMinute
		? formatTotalWithHourlyRate(
			minuteArticleTotal({ex_tax_price: option.ex_tax_price, tax_percent: taxPercent}, quantity),
			hourlyRateOf(option),
			'NOK',
			t
		)
		: formatCurrency(lineTotalOre({ex_tax_price: option.ex_tax_price, tax_percent: taxPercent}, quantity) / 100);

	return (
		<Field>
			<Label>
				{t('bookingfrontend.price_choice_label')}
				{resourceName ? ` (${resourceName})` : ''} <span className="required-asterisk">*</span>
			</Label>
			<Field.Description>{t('bookingfrontend.price_choice_help')}</Field.Description>
			<Select
				id={id}
				ref={ref}
				value={chosen ? String(chosen.price_id) : ''}
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
				{options.map(option => (
					<Select.Option key={option.price_id} value={String(option.price_id)}>
						{optionText(option)}
					</Select.Option>
				))}
			</Select>
			{error && <ValidationMessage>{error}</ValidationMessage>}
			<Paragraph data-size="sm" aria-live="polite">
				{t('bookingfrontend.price')}: {chosen
					? chosenPriceText(chosen)
					: t('bookingfrontend.price_pending')}
			</Paragraph>
		</Field>
	);
});

PriceChoiceField.displayName = 'PriceChoiceField';

export default PriceChoiceField;
