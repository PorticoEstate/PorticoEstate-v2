// Shim for @/app/i18n/ClientTranslationProvider — used in widget builds only.
// The widget passes labels/lang as props, so these hooks return safe no-op values.

export const useTrans = () => (key: string) => key;
export const useClientTranslation = () => ({
	t: (key: string) => key,
	i18n: {language: 'no'},
});
