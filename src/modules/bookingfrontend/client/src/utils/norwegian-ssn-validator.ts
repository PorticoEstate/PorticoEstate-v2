interface ValidationResult {
	isValid: boolean;
	error?: string;
}

class NorwegianSSNValidator {
	private fullRequired: boolean;

	constructor(fullRequired: boolean = true) {
		this.fullRequired = fullRequired;
	}

	/**
	 * Validates Norwegian social security number (fødselsnummer)
	 * Format: DDMMYY + 5 digits (11 digits total)
	 */
	validate(value: string): ValidationResult {
		// Clean the input (remove spaces/dashes)
		const clean = value.replace(/[\s-]/g, '');

		// Check basic format: DDMMYY + 5 digits
		const formatRegex = /^(0[1-9]|[12]\d|3[01])([04][1-9]|[15][0-2])\d{7}$/;

		if (!formatRegex.test(clean)) {
			return {
				isValid: false,
				error: 'Invalid Norwegian social security number format (must be 11 digits with valid date)'
			};
		}

		// Validate using MOD11 algorithm with control digits
		if (!this.mod11OfNumberWithControlDigit(clean)) {
			return {
				isValid: false,
				error: 'Invalid Norwegian social security number (check digits failed)'
			};
		}

		return { isValid: true };
	}

	/**
	 * MOD11 validation algorithm for Norwegian SSN
	 * Uses weights [3,7,6,1,8,9,4,5,2] for first 9 digits
	 */
	private mod11OfNumberWithControlDigit(input: string): boolean {
		let controlNumber = 2;
		let sumForMod = 0;

		const digits = input.split('').map(Number);
		const length = input.length;

		// Calculate weighted sum from right to left (excluding last digit)
		for (let i = length - 2; i >= 0; i--) {
			sumForMod += digits[i] * controlNumber;
			controlNumber++;
			if (controlNumber > 7) {
				controlNumber = 2;
			}
		}

		// Calculate check digit
		const remainder = sumForMod % 11;
		const result = remainder === 0 ? 0 : 11 - remainder;
		const controlDigit = digits[length - 1];

		return result === controlDigit;
	}
}

// Functional approach for validation
export function validateNorwegianSSN(ssn: string): ValidationResult {
	const validator = new NorwegianSSNValidator();
	return validator.validate(ssn);
}

export { NorwegianSSNValidator, ValidationResult };